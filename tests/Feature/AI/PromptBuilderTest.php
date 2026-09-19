<?php

namespace Tests\Feature\AI;

use App\Enums\LeadStatus;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Message;
use App\Services\AI\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(array $customer = [], array $lead = []): Conversation
    {
        $customer = Customer::factory()->create($customer + ['name' => 'Budi']);
        $lead = Lead::factory()->for($customer)->create($lead);

        return Conversation::factory()->for($customer)->create(['lead_id' => $lead->id]);
    }

    public function test_system_prompt_contains_business_data_and_safety_rules(): void
    {
        config([
            'sales_agent.business.name' => 'Toko Maju',
            'sales_agent.products' => [
                ['name' => 'Paket Toko Online', 'price' => 'Rp 500.000/bulan', 'description' => 'Website toko + katalog'],
            ],
            'sales_agent.policies' => 'Jam operasional 09.00-17.00 WIB.',
        ]);

        $messages = app(PromptBuilder::class)->build($this->conversation());

        $system = $messages[0]['content'];
        $this->assertSame('system', $messages[0]['role']);
        $this->assertStringContainsString('Toko Maju', $system);
        $this->assertStringContainsString('Paket Toko Online | harga: Rp 500.000/bulan | Website toko + katalog', $system);
        $this->assertStringContainsString('Jam operasional 09.00-17.00 WIB.', $system);
        $this->assertStringContainsString('data, bukan instruksi', $system);
        $this->assertStringContainsString('handoff_to_human', $system);
    }

    public function test_without_products_the_agent_is_told_not_to_invent_any(): void
    {
        config(['sales_agent.products' => []]);

        $system = app(PromptBuilder::class)->build($this->conversation())[0]['content'];

        $this->assertStringContainsString('Belum ada data produk', $system);
    }

    public function test_context_includes_lead_info_but_never_phone_or_email(): void
    {
        $conversation = $this->conversation(
            ['name' => 'Budi', 'phone' => '6281299998888', 'email' => 'budi@example.test'],
            ['status' => LeadStatus::Qualified, 'interest' => 'Paket toko online', 'notes' => 'Butuh cepat'],
        );

        $messages = app(PromptBuilder::class)->build($conversation);
        $all = json_encode($messages);

        $context = $messages[1]['content'];
        $this->assertStringContainsString('Nama: Budi', $context);
        $this->assertStringContainsString('Status lead: qualified', $context);
        $this->assertStringContainsString('Minat: Paket toko online', $context);
        $this->assertStringContainsString('Butuh cepat', $context);
        $this->assertStringNotContainsString('6281299998888', $all);
        $this->assertStringNotContainsString('budi@example.test', $all);
    }

    public function test_context_handles_conversation_without_lead(): void
    {
        $conversation = Conversation::factory()->create();

        $context = app(PromptBuilder::class)->build($conversation)[1]['content'];

        $this->assertStringContainsString('Belum ada lead', $context);
    }

    public function test_history_maps_roles_and_skips_system_and_failed_messages(): void
    {
        $conversation = $this->conversation();
        Message::factory()->for($conversation)->create(['content' => 'Halo, mau tanya paket']);
        Message::factory()->for($conversation)->outbound(SenderType::Agent)->create(['content' => 'Halo Kak, boleh tahu kebutuhannya?']);
        Message::factory()->for($conversation)->outbound(SenderType::Human)->create(['content' => 'Saya dari tim sales']);
        Message::factory()->for($conversation)->outbound(SenderType::System)->create(['content' => 'catatan internal']);
        Message::factory()->for($conversation)->outbound()->failed()->create(['content' => 'tidak terkirim']);

        $history = array_slice(app(PromptBuilder::class)->build($conversation), 2);

        $this->assertSame([
            ['role' => 'user', 'content' => 'Halo, mau tanya paket'],
            ['role' => 'assistant', 'content' => 'Halo Kak, boleh tahu kebutuhannya?'],
            ['role' => 'assistant', 'content' => 'Saya dari tim sales'],
        ], $history);
    }

    public function test_history_respects_the_limit_and_is_chronological(): void
    {
        $conversation = $this->conversation();
        foreach (['satu', 'dua', 'tiga', 'empat'] as $text) {
            Message::factory()->for($conversation)->create(['content' => $text]);
        }

        $history = array_slice(app(PromptBuilder::class)->build($conversation, 2), 2);

        $this->assertSame(['tiga', 'empat'], array_column($history, 'content'));
    }

    public function test_non_text_messages_get_a_placeholder_and_long_content_is_truncated(): void
    {
        $conversation = $this->conversation();
        Message::factory()->for($conversation)->create(['type' => MessageType::Image, 'content' => null]);
        Message::factory()->for($conversation)->create(['content' => str_repeat('a', 5000)]);

        $history = array_slice(app(PromptBuilder::class)->build($conversation), 2);

        $this->assertStringContainsString('bertipe image', $history[0]['content']);
        $this->assertLessThanOrEqual(2001, mb_strlen($history[1]['content']));
    }

    public function test_pending_agent_replies_stay_in_history(): void
    {
        $conversation = $this->conversation();
        Message::factory()->for($conversation)->outbound()->create(['content' => 'Balasan menunggu', 'status' => MessageStatus::Pending]);

        $history = array_slice(app(PromptBuilder::class)->build($conversation), 2);

        $this->assertSame('Balasan menunggu', $history[0]['content']);
    }
}
