<?php

namespace App\Models;

use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutSlot;
use App\Enums\BoutStatus;
use Database\Factories\BoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $category_id
 * @property BoutBracket $bracket
 * @property int $round
 * @property int $position
 * @property int|null $aka_entry_id
 * @property int|null $ao_entry_id
 * @property int|null $winner_entry_id
 * @property int|null $aka_score
 * @property int|null $ao_score
 * @property BoutDecision|null $decision
 * @property int|null $next_bout_id
 * @property BoutSlot|null $next_slot
 * @property BoutStatus $status
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'category_id', 'bracket', 'round', 'position', 'aka_entry_id', 'ao_entry_id', 'winner_entry_id',
    'aka_score', 'ao_score', 'decision', 'next_bout_id', 'next_slot', 'status', 'finished_at',
])]
class Bout extends Model
{
    /** @use HasFactory<BoutFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bracket' => BoutBracket::class,
            'decision' => BoutDecision::class,
            'next_slot' => BoutSlot::class,
            'status' => BoutStatus::class,
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Entry, $this>
     */
    public function aka(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'aka_entry_id');
    }

    /**
     * @return BelongsTo<Entry, $this>
     */
    public function ao(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'ao_entry_id');
    }

    /**
     * @return BelongsTo<Entry, $this>
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'winner_entry_id');
    }

    /**
     * @return BelongsTo<Bout, $this>
     */
    public function nextBout(): BelongsTo
    {
        return $this->belongsTo(Bout::class, 'next_bout_id');
    }
}
