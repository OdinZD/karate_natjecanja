<?php

namespace App\Models;

use App\Enums\CompetitionStatus;
use Database\Factories\CompetitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon $date
 * @property string $start_time
 * @property string $venue
 * @property string|null $organizer
 * @property Carbon|null $registration_deadline
 * @property CompetitionStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $entries_count
 */
#[Fillable(['name', 'slug', 'date', 'start_time', 'venue', 'organizer', 'registration_deadline', 'status'])]
class Competition extends Model
{
    /** @use HasFactory<CompetitionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'registration_deadline' => 'datetime',
            'status' => CompetitionStatus::class,
        ];
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<Tatami, $this>
     */
    public function tatamis(): HasMany
    {
        return $this->hasMany(Tatami::class);
    }

    /**
     * @return HasManyThrough<Entry, Category, $this>
     */
    public function entries(): HasManyThrough
    {
        return $this->hasManyThrough(Entry::class, Category::class);
    }

    /**
     * Competitions that are shown publicly: everything except drafts.
     *
     * @param  Builder<Competition>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('status', '!=', CompetitionStatus::Draft->value);
    }
}
