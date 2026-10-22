<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Entry;
use App\Models\Placement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Placement>
 */
class PlacementFactory extends Factory
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
            'entry_id' => fn (array $attributes) => Entry::factory()->create([
                'category_id' => $attributes['category_id'],
            ])->id,
            'place' => 1,
        ];
    }
}
