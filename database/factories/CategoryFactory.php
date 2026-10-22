<?php

namespace Database\Factories;

use App\Enums\Discipline;
use App\Models\Category;
use App\Models\Competition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
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
            'tatami_id' => null,
            'name' => fake()->unique()->words(3, true),
            'discipline' => Discipline::Kumite,
            'gender' => null,
            'birth_year_from' => 2010,
            'birth_year_to' => 2012,
            'weight_min' => null,
            'weight_max' => null,
            'bout_duration_seconds' => 120,
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the category is an individual kata category.
     */
    public function kata(): static
    {
        return $this->state(fn (array $attributes) => [
            'discipline' => Discipline::Kata,
        ]);
    }

    /**
     * Indicate that the category is an individual kumite category.
     */
    public function kumite(): static
    {
        return $this->state(fn (array $attributes) => [
            'discipline' => Discipline::Kumite,
        ]);
    }
}
