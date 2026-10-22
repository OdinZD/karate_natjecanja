<?php

namespace Database\Factories;

use App\Models\Club;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Club>
 */
class ClubFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'KK '.fake()->lastName().' '.fake()->unique()->numberBetween(1, 999999),
            'city' => fake()->city(),
            'country' => 'HR',
        ];
    }
}
