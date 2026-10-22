<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\Club;
use App\Models\Competitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Competitor>
 */
class CompetitorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'club_id' => Club::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'gender' => fake()->randomElement(Gender::cases()),
            'birth_date' => fake()->dateTimeBetween('-40 years', '-6 years'),
        ];
    }
}
