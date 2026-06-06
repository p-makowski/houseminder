<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property \Illuminate\Support\Carbon|null $anchor_date
 * @property \Illuminate\Support\Carbon|null $last_completed_at
 * @property float|null $last_metric_value
 * @property \Illuminate\Support\Carbon|null $next_due_at
 * @property float|null $next_due_at_value
 */
#[Fillable(['appliance_id', 'name', 'description', 'interval_value', 'interval_unit', 'anchor_type', 'anchor_date', 'last_completed_at', 'last_metric_value', 'next_due_at', 'next_due_at_value', 'is_confirmed'])]
class MaintenanceTask extends Model
{
    /** @use HasFactory<\Database\Factories\MaintenanceTaskFactory> */
    use HasFactory;

    /** @param Builder<MaintenanceTask> $query */
    public function scopeCalendar(Builder $query): void
    {
        $query->whereIn('interval_unit', ['days', 'weeks', 'months', 'years'])
            ->where('is_confirmed', true)
            ->whereNotNull('next_due_at');
    }

    /** @param Builder<MaintenanceTask> $query */
    public function scopeMetric(Builder $query): void
    {
        $query->whereIn('interval_unit', ['hours', 'km'])
            ->where('is_confirmed', true);
    }

    /** @param Builder<MaintenanceTask> $query */
    public function scopeForHousehold(Builder $query, int $householdId): void
    {
        /** @param Builder<Appliance> $q */
        $query->whereHas('appliance', fn (Builder $q) => $q->where('household_id', $householdId));
    }

    /** @return BelongsTo<Appliance, $this> */
    public function appliance(): BelongsTo
    {
        return $this->belongsTo(Appliance::class);
    }

    /** @return HasMany<ServiceRecord, $this> */
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
