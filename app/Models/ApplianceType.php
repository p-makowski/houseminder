<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ApplianceTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['household_id', 'name'])]
class ApplianceType extends Model
{
    /** @use HasFactory<ApplianceTypeFactory> */
    use HasFactory;

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<Appliance, $this> */
    public function appliances(): HasMany
    {
        return $this->hasMany(Appliance::class);
    }
}
