<?php

namespace App\Models;

use Database\Factories\EntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $category_id
 * @property int $competitor_id
 * @property string|null $weight
 * @property int|null $seed
 * @property int|null $club_import_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['category_id', 'competitor_id', 'weight', 'seed', 'club_import_id'])]
class Entry extends Model
{
    /** @use HasFactory<EntryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
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
     * The weight for display ("33", "33,5" in Croatian), or null for an entry without one.
     */
    public function weightLabel(): ?string
    {
        if ($this->weight === null) {
            return null;
        }

        $number = rtrim(rtrim(number_format((float) $this->weight, 2, '.', ''), '0'), '.');

        return app()->getLocale() === 'hr' ? str_replace('.', ',', $number) : $number;
    }

    /**
     * The club import that created the entry, if it came from one.
     *
     * @return BelongsTo<ClubImport, $this>
     */
    public function clubImport(): BelongsTo
    {
        return $this->belongsTo(ClubImport::class);
    }

    /**
     * @return BelongsTo<Competitor, $this>
     */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }
}
