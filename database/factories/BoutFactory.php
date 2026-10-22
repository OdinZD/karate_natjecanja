<?php

namespace Database\Factories;

use App\Enums\BoutBracket;
use App\Enums\BoutStatus;
use App\Models\Bout;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bout>
 */
class BoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'bracket' => BoutBracket::Main,
            'round' => 1,
            'position' => fake()->unique()->numberBetween(1, 10000),
            'status' => BoutStatus::Pending,
        ];
    }
}
