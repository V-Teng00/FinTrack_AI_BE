<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'store_name' => $this->faker->company(),
            'date' => now(),
            'total' => $this->faker->randomFloat(2, 5, 200),
            'currency' => 'MYR',
            'category' => $this->faker->randomElement([
                'Groceries', 'Food & Drink', 'Transport', 'Health',
            ]),
        ];
    }
}
