<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['household_id', 'appliance_type_id', 'name', 'model', 'purchase_date', 'is_plan_confirmed'])]
class Appliance extends Model
{
    use HasFactory;

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function applianceType(): BelongsTo
    {
        return $this->belongsTo(ApplianceType::class);
    }

    public function maintenanceTasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class);
    }

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'is_plan_confirmed' => 'boolean',
        ];
    }
}
