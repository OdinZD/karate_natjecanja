<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\ClubImport;
use App\Models\Competition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClubImport>
 */
class ClubImportFactory extends Factory
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
            'club_id' => Club::factory(),
            'user_id' => null,
            'file_name' => 'prijave.xlsx',
            'mode' => 'all',
            'status' => ClubImport::IMPORTED,
            'rows_total' => 0,
            'rows_valid' => 0,
            'rows_failed' => 0,
            'entries_created' => 0,
            'entries_replaced' => 0,
            'entries_skipped' => 0,
            'competitors_created' => 0,
            'error_count' => 0,
            'errors' => null,
        ];
    }
}
