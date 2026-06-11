<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory;

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role');
    }

    /** @return HasMany<Appliance, $this> */
    public function appliances(): HasMany
    {
        return $this->hasMany(Appliance::class);
    }

    /** @return HasMany<ApplianceType, $this> */
    public function applianceTypes(): HasMany
    {
        return $this->hasMany(ApplianceType::class);
    }
}
