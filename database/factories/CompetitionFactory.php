<?php

namespace Database\Factories;

use App\Enums\CompetitionStatus;
use App\Models\Competition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Competition>
 */
class CompetitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $name = fake()->lastName().' Open',
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'date' => fake()->dateTimeBetween('+1 week', '+6 months'),
            'start_time' => '10:00',
            'venue' => fake()->city(),
            'organizer' => 'KK '.fake()->city(),
            'registration_deadline' => fake()->dateTimeBetween('now', '+1 week'),
            'status' => CompetitionStatus::Draft,
        ];
    }
}
