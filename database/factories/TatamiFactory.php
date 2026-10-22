<?php

namespace Database\Factories;

use App\Models\Competition;
use App\Models\Tatami;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tatami>
 */
class TatamiFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            'number' => fake()->unique()->numberBetween(1, 200),
            'name' => null,
        ];
    }
}
