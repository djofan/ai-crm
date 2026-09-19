<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerIdentity>
 */
class CustomerIdentityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'channel' => Channel::WhatsApp,
            'identifier' => '628'.fake()->unique()->numerify('##########'),
        ];
    }
}
