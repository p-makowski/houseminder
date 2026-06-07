<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Appliance;
use App\Models\MaintenanceTask;
use Livewire\Volt\Volt;

class ApplianceShowSectionsTest extends ApplianceTestCase
{
    private Appliance $appliance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appliance = Appliance::factory()->create(['household_id' => $this->household->id]);
    }

    public function test_overdue_task_appears_in_overdue_section(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'Filter Expired Task',
            'next_due_at'  => now()->subDay(),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->assertSee('Overdue')
            ->assertSee('Filter Expired Task');
    }

    public function test_due_this_week_task_appears_in_due_this_week_section(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'Due This Week Task',
            'next_due_at'  => now()->addDays(3),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->assertSee('Due this week')
            ->assertSee('border-yellow-200');
    }

    public function test_this_month_task_appears_in_this_month_section(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'This Month Section Task',
            'next_due_at'  => now()->addDays(15),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->assertSee('This month')
            ->assertSee('This Month Section Task');
    }

    public function test_upcoming_task_appears_in_upcoming_section(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'Far Future Task',
            'next_due_at'  => now()->addDays(45),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->assertSee('Upcoming')
            ->assertSee('Far Future Task');
    }

    public function test_metric_task_appears_in_manual_tracking_section(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id'  => $this->appliance->id,
            'interval_unit' => 'km',
            'next_due_at'   => null,
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->assertSee('Manual tracking')
            ->assertSee('border-gray-200');
    }

    public function test_this_month_tasks_sorted_alphabetically_by_name(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'Zeta task',
            'next_due_at'  => now()->addDays(15),
        ]);
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'Alpha task',
            'next_due_at'  => now()->addDays(20),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('setSortBy', 'name')
            ->assertSee('This month')
            ->assertSeeInOrder(['Alpha task', 'Zeta task']);
    }
}
