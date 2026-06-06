<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MaintenanceTask;
use App\Models\ServiceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRecord>
 */
class ServiceRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'maintenance_task_id' => MaintenanceTask::factory(),
            'completed_at' => now(),
            'metric_reading' => null,
            'notes' => null,
        ];
    }
}
