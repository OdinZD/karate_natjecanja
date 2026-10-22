<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Competitor;
use App\Models\Entry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entry>
 */
class EntryFactory extends Factory
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
            'competitor_id' => Competitor::factory(),
            'weight' => null,
            'seed' => null,
        ];
    }
}
