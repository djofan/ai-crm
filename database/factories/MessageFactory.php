<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Catatan: `channel` sengaja tidak diisi di sini; Message mengambilnya dari conversation.
 *
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'type' => MessageType::Text,
            'content' => fake()->sentence(),
            'external_message_id' => fake()->unique()->uuid(),
            'status' => MessageStatus::Received,
        ];
    }

    public function outbound(SenderType $sender = SenderType::Agent): static
    {
        return $this->state(fn () => [
            'direction' => MessageDirection::Outbound,
            'sender_type' => $sender,
            'status' => MessageStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function failed(string $error = 'Provider error'): static
    {
        return $this->state(fn () => [
            'status' => MessageStatus::Failed,
            'error' => $error,
            'sent_at' => null,
        ]);
    }
}
