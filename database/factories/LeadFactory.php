<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'status' => LeadStatus::New,
            'source' => 'whatsapp',
            'interest' => fake()->randomElement(['Paket toko online', 'Website company profile', 'Aplikasi custom']),
        ];
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
