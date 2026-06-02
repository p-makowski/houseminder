<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['household_id', 'name'])]
class ApplianceType extends Model
{
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function appliances(): HasMany
    {
        return $this->hasMany(Appliance::class);
    }
}
