<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['maintenance_task_id', 'completed_at', 'metric_reading', 'notes'])]
class ServiceRecord extends Model
{
    public function maintenanceTask(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class);
    }

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}
