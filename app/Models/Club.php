<?php

namespace App\Models;

use Database\Factories\ClubFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $city
 * @property string $country
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'city', 'country'])]
class Club extends Model
{
    /** @use HasFactory<ClubFactory> */
    use HasFactory;

    /**
     * @return HasMany<Competitor, $this>
     */
    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }
}
