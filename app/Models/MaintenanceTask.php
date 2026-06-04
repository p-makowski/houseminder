<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['appliance_id', 'name', 'description', 'interval_value', 'interval_unit', 'anchor_type', 'anchor_date', 'last_completed_at', 'last_metric_value', 'next_due_at', 'next_due_at_value', 'is_confirmed'])]
class MaintenanceTask extends Model
{
    use HasFactory;

    public function appliance(): BelongsTo
    {
        return $this->belongsTo(Appliance::class);
    }

    public function serviceRecords(): HasMany
    {
        return $this->hasMany(ServiceRecord::class);
    }

    protected function casts(): array
    {
        return [
            'anchor_date' => 'date',
            'last_completed_at' => 'datetime',
            'last_metric_value' => 'float',
            'next_due_at' => 'datetime',
            'next_due_at_value' => 'float',
            'is_confirmed' => 'boolean',
        ];
    }
}
