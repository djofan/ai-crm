<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'channel' => Channel::WhatsApp,
            'status' => ConversationStatus::Open,
            'ai_enabled' => true,
        ];
    }
}
