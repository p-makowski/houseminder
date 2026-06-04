<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Appliance;
use App\Models\MaintenanceTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceTask>
 */
class MaintenanceTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'appliance_id' => Appliance::factory(),
            'name' => fake()->words(3, true),
            'description' => null,
            'interval_value' => 6,
            'interval_unit' => 'months',
            'anchor_type' => 'from_last_done',
            'anchor_date' => null,
            'last_completed_at' => null,
            'last_metric_value' => null,
            'next_due_at' => now()->addMonths(6),
            'next_due_at_value' => null,
            'is_confirmed' => false,
        ];
    }
}
