<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\MaintenanceTask;

class DashboardThisMonthBoundaryTest extends DashboardTestCase
{
    public function test_task_due_seven_days_and_one_second_from_now_is_in_this_month(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'ThisMonth Boundary Lower Task',
            'next_due_at'  => now()->addDays(7)->addSecond(),
            'interval_unit' => 'months',
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')
            ->assertSee('ThisMonth Boundary Lower Task')
            ->assertSee('No upcoming tasks.');
    }

    public function test_task_due_exactly_thirty_days_from_now_is_in_this_month(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'ThisMonth Boundary Upper Task',
            'next_due_at'  => now()->addDays(30),
            'interval_unit' => 'months',
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')
            ->assertSee('ThisMonth Boundary Upper Task')
            ->assertSee('No upcoming tasks.');
    }

    public function test_task_due_one_second_past_thirty_days_is_upcoming(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'Upcoming Boundary Task',
            'next_due_at'  => now()->addDays(30)->addSecond(),
            'interval_unit' => 'months',
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')
            ->assertSee('Upcoming Boundary Task')
            ->assertSee('Nothing due this month.');
    }

    public function test_task_due_mid_window_fifteen_days_is_in_this_month(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'ThisMonth Mid Window Task',
            'next_due_at'  => now()->addDays(15),
            'interval_unit' => 'months',
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')
            ->assertSee('ThisMonth Mid Window Task')
            ->assertSee('No upcoming tasks.');
    }
}
