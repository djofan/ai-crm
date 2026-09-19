<?php

namespace Tests\Feature\AI;

use App\Enums\AgentRunStatus;
use App\Enums\LeadStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Message;
use App\Services\AI\SalesAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesOpenAI;
use Tests\TestCase;

class SalesAgentTest extends TestCase
{
    use FakesOpenAI;
    use RefreshDatabase;

    private Conversation $conversation;

    private Lead $lead;

    private Message $incoming;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureOpenAI();

        $customer = Customer::factory()->create(['name' => 'Budi']);
        $this->lead = Lead::factory()->for($customer)->create();
        $this->conversation = Conversation::factory()->for($customer)->create(['lead_id' => $this->lead->id]);
        $this->incoming = Message::factory()->for($this->conversation)->create(['content' => 'Halo, saya mau tanya paket toko online']);
    }

    private function agent(): SalesAgent
    {
        return app(SalesAgent::class);
    }

    private function outboundMessages()
    {
        return Message::where('direction', MessageDirection::Outbound->value)->get();
    }

    public function test_agent_updates_lead_schedules_follow_up_and_stores_the_reply(): void
    {
        $this->fakeOpenAIToolCalls([
            // Sengaja tidak berurutan: agent harus menjalankan send_message terakhir.
            ['send_message', ['message' => 'Halo Kak Budi! Untuk toko online, boleh tahu kira-kira produk apa yang dijual?']],
            ['update_lead', ['status' => 'qualified', 'interest' => 'Paket toko online', 'estimated_value' => 5000000, 'notes' => 'Tanya paket toko online']],
            ['schedule_follow_up', ['hours' => 24, 'reason' => 'Belum menyebut kebutuhan detail']],
        ]);

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertSame(AgentRunStatus::Completed, $run->status);
        $this->assertSame($this->incoming->id, $run->trigger_message_id);
        $this->assertSame('test-model-2026', $run->model);
        $this->assertSame(120, $run->usage['total_tokens']);
        $this->assertSame(['update_lead', 'schedule_follow_up', 'send_message'], array_column($run->actions, 'tool'));
        $this->assertSame([true, true, true], array_column($run->actions, 'success'));

        $lead = $this->lead->fresh();
        $this->assertSame(LeadStatus::Qualified, $lead->status);
        $this->assertSame('Paket toko online', $lead->interest);
        $this->assertSame('5000000.00', $lead->estimated_value);
        $this->assertStringContainsString('Tanya paket toko online', $lead->notes);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $lead->next_follow_up_at->timestamp, 5);
        $this->assertSame('Belum menyebut kebutuhan detail', $lead->metadata['follow_up_reason']);

        $reply = $this->outboundMessages()->sole();
        $this->assertSame(SenderType::Agent, $reply->sender_type);
        $this->assertSame(MessageStatus::Pending, $reply->status);
        $this->assertNull($reply->external_message_id);
        $this->assertStringStartsWith('Halo Kak Budi!', $reply->content);
        $this->assertSame($run->id, $reply->metadata['agent_run_id']);
    }

    public function test_request_uses_required_tool_choice_all_tools_and_conversation_history(): void
    {
        $this->fakeOpenAIToolCalls([['send_message', ['message' => 'Halo']]]);

        $this->agent()->handle($this->conversation, $this->incoming);

        Http::assertSent(function (Request $request) {
            $toolNames = array_map(fn ($t) => $t['function']['name'], $request['tools']);
            sort($toolNames);

            $messages = $request['messages'];
            $last = end($messages);

            return $request['tool_choice'] === 'required'
                && $toolNames === ['handoff_to_human', 'schedule_follow_up', 'send_message', 'update_lead']
                && $last['role'] === 'user'
                && $last['content'] === 'Halo, saya mau tanya paket toko online';
        });
    }

    public function test_the_same_incoming_message_is_never_processed_twice(): void
    {
        $this->fakeOpenAIToolCalls([['send_message', ['message' => 'Halo']]]);

        $first = $this->agent()->handle($this->conversation, $this->incoming);
        $second = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertTrue($first->is($second));
        Http::assertSentCount(1);
        $this->assertCount(1, $this->outboundMessages());
        $this->assertSame(1, AgentRun::count());
    }

    public function test_a_failed_run_can_be_retried_for_the_same_message(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['type' => 'server_error']], 500)
            ->push($this->openAIToolResponse([['send_message', ['message' => 'Halo lagi']]]))]);

        $failed = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertSame(AgentRunStatus::Failed, $failed->status);
        $this->assertStringContainsString('HTTP 500', $failed->error);
        $this->assertCount(0, $this->outboundMessages());

        $retried = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertTrue($retried->is($failed));
        $this->assertSame(AgentRunStatus::Completed, $retried->status);
        $this->assertNull($retried->error);
        $this->assertCount(1, $this->outboundMessages());
        Http::assertSentCount(2);
    }

    public function test_a_run_that_is_currently_running_is_left_alone(): void
    {
        Http::fake();
        AgentRun::create([
            'conversation_id' => $this->conversation->id,
            'trigger_message_id' => $this->incoming->id,
            'status' => AgentRunStatus::Running,
            'started_at' => now(),
        ]);

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertSame(AgentRunStatus::Running, $run->status);
        Http::assertNothingSent();
    }

    public function test_a_stale_running_run_is_picked_up_again(): void
    {
        $this->fakeOpenAIToolCalls([['send_message', ['message' => 'Halo']]]);
        $stale = AgentRun::create([
            'conversation_id' => $this->conversation->id,
            'trigger_message_id' => $this->incoming->id,
            'status' => AgentRunStatus::Running,
            'started_at' => now()->subHour(),
        ]);
        DB::table('agent_runs')->where('id', $stale->id)->update(['updated_at' => now()->subMinutes(10)]);

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertSame(AgentRunStatus::Completed, $run->status);
        $this->assertCount(1, $this->outboundMessages());
    }

    public function test_agent_does_nothing_when_ai_is_disabled_for_the_conversation(): void
    {
        Http::fake();
        $this->conversation->update(['ai_enabled' => false]);

        $result = $this->agent()->handle($this->conversation->fresh(), $this->incoming);

        $this->assertNull($result);
        Http::assertNothingSent();
        $this->assertSame(0, AgentRun::count());
    }

    public function test_handoff_disables_ai_and_records_the_reason(): void
    {
        $this->fakeOpenAIToolCalls([
            ['handoff_to_human', ['reason' => 'Pelanggan minta bicara dengan manusia']],
            ['send_message', ['message' => 'Baik Kak, saya sambungkan ke tim kami ya.']],
        ]);

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $conversation = $this->conversation->fresh();
        $this->assertFalse($conversation->ai_enabled);
        $this->assertSame('Pelanggan minta bicara dengan manusia', $conversation->metadata['handoff']['reason']);
        $this->assertSame(AgentRunStatus::Completed, $run->status);
        $this->assertCount(1, $this->outboundMessages());

        // Pesan berikutnya tidak diproses AI lagi.
        Http::fake();
        $next = Message::factory()->for($conversation)->create();
        $this->assertNull($this->agent()->handle($conversation, $next));
        Http::assertNothingSent();
    }

    public function test_agent_cannot_mark_a_lead_as_won(): void
    {
        $this->fakeOpenAIToolCalls([
            ['update_lead', ['status' => 'won']],
            ['send_message', ['message' => 'Terima kasih!']],
        ]);

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertSame(LeadStatus::New, $this->lead->fresh()->status);
        $this->assertFalse($run->actions[0]['success']);
        $this->assertTrue($run->actions[1]['success']);
        $this->assertSame(AgentRunStatus::Completed, $run->status);
        $this->assertCount(1, $this->outboundMessages());
    }

    public function test_marking_a_lead_as_lost_sets_closed_at(): void
    {
        $this->fakeOpenAIToolCalls([
            ['update_lead', ['status' => 'lost', 'notes' => 'Tidak jadi']],
            ['send_message', ['message' => 'Baik, terima kasih Kak.']],
        ]);

        $this->agent()->handle($this->conversation, $this->incoming);

        $lead = $this->lead->fresh();
        $this->assertSame(LeadStatus::Lost, $lead->status);
        $this->assertNotNull($lead->closed_at);
    }

    public function test_closed_leads_cannot_be_changed_by_the_agent(): void
    {
        $this->lead->update(['status' => LeadStatus::Won]);
        $this->fakeOpenAIToolCalls([
            ['update_lead', ['status' => 'lost']],
            ['schedule_follow_up', ['hours' => 5]],
            ['send_message', ['message' => 'Halo']],
        ]);

        $run = $this->agent()->handle($this->conversation->fresh(), $this->incoming);

        $this->assertSame(LeadStatus::Won, $this->lead->fresh()->status);
        $this->assertNull($this->lead->fresh()->next_follow_up_at);
        $this->assertSame([false, false, true], array_column($run->actions, 'success'));
    }

    public function test_follow_up_hours_are_bounded(): void
    {
        $this->fakeOpenAIToolCalls([
            ['schedule_follow_up', ['hours' => 100000]],
            ['send_message', ['message' => 'Halo']],
        ]);

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertFalse($run->actions[0]['success']);
        $this->assertNull($this->lead->fresh()->next_follow_up_at);
    }

    public function test_only_the_first_send_message_is_stored(): void
    {
        $this->fakeOpenAIToolCalls([
            ['send_message', ['message' => 'Balasan pertama']],
            ['send_message', ['message' => 'Balasan kedua']],
        ]);

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertSame('Balasan pertama', $this->outboundMessages()->sole()->content);
        $this->assertSame([true, false], array_column($run->actions, 'success'));
    }

    public function test_unknown_tools_are_recorded_and_ignored(): void
    {
        $this->fakeOpenAIToolCalls([
            ['delete_everything', ['confirm' => true]],
            ['send_message', ['message' => 'Halo']],
        ]);

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertSame('delete_everything', $run->actions[1]['tool']);
        $this->assertFalse($run->actions[1]['success']);
        $this->assertSame(AgentRunStatus::Completed, $run->status);
    }

    public function test_invalid_json_arguments_do_not_crash_the_agent(): void
    {
        $this->fakeOpenAIToolCalls([['send_message', '{oops']]);

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertSame(AgentRunStatus::Failed, $run->status);
        $this->assertNull($run->actions[0]['arguments']);
        $this->assertCount(0, $this->outboundMessages());
    }

    public function test_overlong_reply_is_rejected_and_run_is_marked_failed(): void
    {
        $this->fakeOpenAIToolCalls([['send_message', ['message' => str_repeat('a', 1001)]]]);

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertSame(AgentRunStatus::Failed, $run->status);
        $this->assertCount(0, $this->outboundMessages());
    }

    public function test_run_without_a_reply_or_handoff_is_marked_failed(): void
    {
        $this->fakeOpenAIToolCalls([['update_lead', ['interest' => 'Toko online']]]);

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertSame(AgentRunStatus::Failed, $run->status);
        $this->assertSame('Toko online', $this->lead->fresh()->interest);
        $this->assertCount(0, $this->outboundMessages());
    }

    public function test_plain_text_answer_without_tool_calls_is_never_sent_to_the_customer(): void
    {
        Http::fake(['*' => Http::response(['choices' => [[
            'finish_reason' => 'stop',
            'message' => ['role' => 'assistant', 'content' => 'Halo, ini jawaban tanpa tool'],
        ]]])]);

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertSame(AgentRunStatus::Failed, $run->status);
        $this->assertStringContainsString('tidak memanggil tool', $run->error);
        $this->assertCount(0, $this->outboundMessages());
    }

    public function test_conversation_without_a_lead_can_still_be_answered(): void
    {
        $conversation = Conversation::factory()->create();
        $incoming = Message::factory()->for($conversation)->create(['content' => 'Halo']);
        $this->fakeOpenAIToolCalls([
            ['update_lead', ['status' => 'qualified']],
            ['send_message', ['message' => 'Halo!']],
        ]);

        $run = $this->agent()->handle($conversation, $incoming);

        $this->assertFalse($run->actions[0]['success']);
        $this->assertSame(AgentRunStatus::Completed, $run->status);
        $this->assertSame(1, Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
    }

    public function test_missing_configuration_is_recorded_as_a_failed_run(): void
    {
        config(['services.openai.api_key' => null]);
        Http::fake();

        $run = $this->agent()->handle($this->conversation, $this->incoming);

        $this->assertSame(AgentRunStatus::Failed, $run->status);
        $this->assertStringContainsString('OPENAI_API_KEY', $run->error);
        Http::assertNothingSent();
    }

    public function test_runs_without_a_trigger_message_are_not_deduplicated(): void
    {
        $this->fakeOpenAIToolCalls([['send_message', ['message' => 'Halo']]]);

        $this->agent()->handle($this->conversation);
        $this->agent()->handle($this->conversation);

        $this->assertSame(2, AgentRun::count());
        Http::assertSentCount(2);
    }
}
