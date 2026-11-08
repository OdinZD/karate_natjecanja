<?php

namespace App\Models;

use Database\Factories\ClubImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to import a club's entry file into a competition, kept whether it was saved or refused.
 *
 * @property int $id
 * @property int $competition_id
 * @property int $club_id
 * @property int|null $user_id
 * @property string $file_name
 * @property string $mode
 * @property string $status
 * @property int $rows_total
 * @property int $rows_valid
 * @property int $rows_failed
 * @property int $entries_created
 * @property int $entries_replaced
 * @property int $entries_skipped
 * @property int $competitors_created
 * @property int $error_count
 * @property array<int, string>|null $errors
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'competition_id', 'club_id', 'user_id', 'file_name', 'mode', 'status', 'rows_total', 'rows_valid', 'rows_failed',
    'entries_created', 'entries_replaced', 'entries_skipped', 'competitors_created', 'error_count', 'errors',
])]
class ClubImport extends Model
{
    /** @use HasFactory<ClubImportFactory> */
    use HasFactory;

    public const IMPORTED = 'imported';

    public const REJECTED = 'rejected';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'errors' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * @return BelongsTo<Club, $this>
     */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
