<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\MaintenanceTask;

class DashboardBoundaryTest extends DashboardTestCase
{
    public function test_task_due_exactly_now_is_in_due_this_week(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Boundary Now Task',
            'next_due_at' => now(),
            'interval_unit' => 'months',
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')
            ->assertSee('Boundary Now Task')
            ->assertSee('No overdue tasks.');
    }

    public function test_task_due_one_second_ago_is_overdue(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Boundary SubSecond Task',
            'next_due_at' => now()->subSecond(),
            'interval_unit' => 'months',
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')
            ->assertSee('Boundary SubSecond Task')
            ->assertSee('Nothing due this week.');
    }

    public function test_task_due_exactly_seven_days_from_now_is_in_due_this_week(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Boundary AddDays7 Task',
            'next_due_at' => now()->addDays(7),
            'interval_unit' => 'months',
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')
            ->assertSee('Boundary AddDays7 Task')
            ->assertSee('No upcoming tasks.');
    }

    public function test_task_due_one_second_past_seven_days_is_in_this_month(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Boundary AddDays7PlusSecond Task',
            'next_due_at' => now()->addDays(7)->addSecond(),
            'interval_unit' => 'months',
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')
            ->assertSee('Boundary AddDays7PlusSecond Task')
            ->assertSee('Nothing due this week.')
            ->assertSee('No upcoming tasks.');
    }
}
