<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\LeadStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_has_identities_leads_conversations_and_messages(): void
    {
        $customer = Customer::factory()->create();
        CustomerIdentity::factory()->for($customer)->create();
        $lead = Lead::factory()->for($customer)->create();
        $conversation = Conversation::factory()->for($customer)->create(['lead_id' => $lead->id]);
        Message::factory()->count(2)->for($conversation)->create();

        $this->assertCount(1, $customer->identities);
        $this->assertCount(1, $customer->leads);
        $this->assertCount(1, $customer->conversations);
        $this->assertCount(2, $customer->messages);
        $this->assertTrue($conversation->lead->is($lead));
    }

    public function test_identity_is_unique_per_channel_but_can_repeat_across_channels(): void
    {
        $customer = Customer::factory()->create();
        CustomerIdentity::create([
            'customer_id' => $customer->id,
            'channel' => Channel::WhatsApp,
            'identifier' => '6281234567890',
        ]);

        // Channel berbeda dengan identifier sama: boleh.
        CustomerIdentity::create([
            'customer_id' => $customer->id,
            'channel' => Channel::Telegram,
            'identifier' => '6281234567890',
        ]);
        $this->assertSame(2, CustomerIdentity::count());

        // Channel + identifier sama: harus ditolak database.
        $this->expectException(UniqueConstraintViolationException::class);
        CustomerIdentity::create([
            'customer_id' => Customer::factory()->create()->id,
            'channel' => Channel::WhatsApp,
            'identifier' => '6281234567890',
        ]);
    }

    public function test_external_message_id_is_unique_per_channel(): void
    {
        $conversation = Conversation::factory()->create();
        Message::factory()->for($conversation)->create(['external_message_id' => 'wamid.ABC']);

        $this->expectException(UniqueConstraintViolationException::class);
        Message::factory()->for($conversation)->create(['external_message_id' => 'wamid.ABC']);
    }

    public function test_same_external_message_id_is_allowed_on_a_different_channel(): void
    {
        $whatsapp = Conversation::factory()->create(['channel' => Channel::WhatsApp]);
        $telegram = Conversation::factory()->create(['channel' => Channel::Telegram]);

        Message::factory()->for($whatsapp)->create(['external_message_id' => '42']);
        Message::factory()->for($telegram)->create(['external_message_id' => '42']);

        $this->assertSame(2, Message::where('external_message_id', '42')->count());
    }

    public function test_messages_without_external_id_do_not_conflict(): void
    {
        $conversation = Conversation::factory()->create();

        Message::factory()->for($conversation)->count(2)->create(['external_message_id' => null]);

        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_message_inherits_channel_from_conversation_and_updates_last_message_at(): void
    {
        $conversation = Conversation::factory()->create(['channel' => Channel::Telegram]);
        $this->assertNull($conversation->last_message_at);

        $message = Message::factory()->for($conversation)->create();

        $this->assertSame(Channel::Telegram, $message->fresh()->channel);
        $this->assertNotNull($conversation->fresh()->last_message_at);
    }

    public function test_message_casts_enums_and_metadata(): void
    {
        $message = Message::factory()
            ->outbound(SenderType::Agent)
            ->create(['metadata' => ['provider' => 'fake']]);

        $message = $message->fresh();

        $this->assertSame(MessageDirection::Outbound, $message->direction);
        $this->assertSame(SenderType::Agent, $message->sender_type);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame(['provider' => 'fake'], $message->metadata);
        $this->assertFalse($message->isInbound());
    }

    public function test_failed_message_keeps_error(): void
    {
        $message = Message::factory()->outbound()->failed('Token expired')->create();

        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);
        $this->assertSame('Token expired', $message->fresh()->error);
    }

    public function test_recent_messages_are_chronological_and_limited(): void
    {
        $conversation = Conversation::factory()->create();
        foreach (['satu', 'dua', 'tiga', 'empat'] as $text) {
            Message::factory()->for($conversation)->create(['content' => $text]);
        }

        $recent = $conversation->recentMessages(3);

        $this->assertSame(['dua', 'tiga', 'empat'], $recent->pluck('content')->all());
    }

    public function test_open_lead_scope_excludes_won_and_lost(): void
    {
        Lead::factory()->status(LeadStatus::New)->create();
        Lead::factory()->status(LeadStatus::Qualified)->create();
        Lead::factory()->status(LeadStatus::Won)->create();
        Lead::factory()->status(LeadStatus::Lost)->create();

        $this->assertSame(2, Lead::open()->count());
        $this->assertTrue(LeadStatus::New->isOpen());
        $this->assertFalse(LeadStatus::Won->isOpen());
    }

    public function test_deleting_customer_cascades_to_related_records(): void
    {
        $customer = Customer::factory()->create();
        CustomerIdentity::factory()->for($customer)->create();
        Lead::factory()->for($customer)->create();
        $conversation = Conversation::factory()->for($customer)->create();
        Message::factory()->for($conversation)->create();

        $customer->delete();

        $this->assertSame(0, CustomerIdentity::count());
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Message::count());
    }

    public function test_deleting_lead_keeps_conversation(): void
    {
        $lead = Lead::factory()->create();
        $conversation = Conversation::factory()->create([
            'customer_id' => $lead->customer_id,
            'lead_id' => $lead->id,
        ]);

        $lead->delete();

        $this->assertNull($conversation->fresh()->lead_id);
    }
}
