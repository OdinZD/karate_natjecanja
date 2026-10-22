<?php

namespace App\Models;

use App\Enums\BoutBracket;
use App\Enums\BoutDecision;
use App\Enums\BoutStatus;
use App\Enums\Discipline;
use App\Enums\Gender;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $competition_id
 * @property int|null $tatami_id
 * @property string $name
 * @property Discipline $discipline
 * @property Gender|null $gender
 * @property int $birth_year_from
 * @property int $birth_year_to
 * @property string|null $weight_min
 * @property string|null $weight_max
 * @property int $bout_duration_seconds
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $entries_count
 * @property-read int|null $any_bouts_count
 * @property-read int|null $open_bouts_count
 * @property-read int|null $played_bouts_count
 */
#[Fillable([
    'competition_id', 'tatami_id', 'name', 'discipline', 'gender', 'birth_year_from', 'birth_year_to',
    'weight_min', 'weight_max', 'bout_duration_seconds', 'sort_order',
])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discipline' => Discipline::class,
            'gender' => Gender::class,
            'weight_min' => 'decimal:2',
            'weight_max' => 'decimal:2',
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
     * @return BelongsTo<Tatami, $this>
     */
    public function tatami(): BelongsTo
    {
        return $this->belongsTo(Tatami::class);
    }

    /**
     * @return HasMany<Entry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /**
     * @return HasMany<Bout, $this>
     */
    public function bouts(): HasMany
    {
        return $this->hasMany(Bout::class);
    }

    /**
     * @return HasMany<Placement, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(Placement::class);
    }

    /**
     * Whether a draw has been generated for the category.
     */
    public function hasDraw(): bool
    {
        return $this->bouts()->exists();
    }

    /**
     * Whether any real (non-bye) bout of the category has been decided.
     */
    public function hasPlayedBouts(): bool
    {
        return $this->bouts()
            ->where('status', BoutStatus::Finished)
            ->where('decision', '!=', BoutDecision::Bye)
            ->exists();
    }

    /**
     * The last round of the main bracket (the final), or 0 when there is no draw.
     */
    public function finalRound(): int
    {
        return (int) $this->bouts()->where('bracket', BoutBracket::Main)->max('round');
    }

    /**
     * The weight range for display ("30–52 kg", "up to 52 kg", "from 30 kg"), or null without limits.
     */
    public function weightLabel(): ?string
    {
        $min = $this->weight_min !== null ? $this->formatKilograms($this->weight_min) : null;
        $max = $this->weight_max !== null ? $this->formatKilograms($this->weight_max) : null;

        return match (true) {
            $min !== null && $max !== null => $min.'–'.$max.' kg',
            $max !== null => $this->translate('up to :max kg', ['max' => $max]),
            $min !== null => $this->translate('from :min kg', ['min' => $min]),
            default => null,
        };
    }

    private function formatKilograms(string $value): string
    {
        $number = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return app()->getLocale() === 'hr' ? str_replace('.', ',', $number) : $number;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function translate(string $key, array $replace): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
