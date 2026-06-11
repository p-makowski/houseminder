<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Appliance;
use App\Models\Household;
use App\Models\MaintenanceTask;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Volt\Volt;

class DashboardPageTest extends DashboardTestCase
{
    public function test_authenticated_user_sees_dashboard(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('Dashboard');
    }

    public function test_overdue_task_appears_in_overdue_section(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Overdue Check',
            'next_due_at' => now()->subDay(),
            'interval_unit' => 'months',
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')->assertSee('Overdue Check');
    }

    public function test_due_this_week_task_appears_in_due_this_week_section(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Soon Due Check',
            'next_due_at' => now()->addDays(3),
            'interval_unit' => 'months',
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')->assertSee('Soon Due Check');
    }

    public function test_upcoming_task_appears_in_upcoming_section(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Upcoming Check',
            'next_due_at' => now()->addDays(30),
            'interval_unit' => 'months',
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')->assertSee('Upcoming Check');
    }

    public function test_metric_task_appears_in_manual_tracking_section(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Metric Check',
            'interval_unit' => 'hours',
            'interval_value' => 500,
            'next_due_at' => null,
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')
            ->assertSee('Metric Check')
            ->assertDontSee('Mark done');
    }

    public function test_unconfirmed_task_does_not_appear(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Draft Task',
            'next_due_at' => now()->subDay(),
            'interval_unit' => 'months',
            'is_confirmed' => false,
        ]);

        $this->get('/dashboard')->assertDontSee('Draft Task');
    }

    public function test_unconfirmed_task_does_not_appear_in_due_this_week(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Draft Due This Week Task',
            'next_due_at' => now()->addDays(3),
            'interval_unit' => 'months',
            'is_confirmed' => false,
        ]);

        $this->get('/dashboard')->assertDontSee('Draft Due This Week Task');
    }

    public function test_unconfirmed_task_does_not_appear_in_upcoming(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Draft Upcoming Task',
            'next_due_at' => now()->addDays(30),
            'interval_unit' => 'months',
            'is_confirmed' => false,
        ]);

        $this->get('/dashboard')->assertDontSee('Draft Upcoming Task');
    }

    public function test_task_from_different_household_does_not_appear(): void
    {
        $otherHousehold = Household::factory()->create();
        $otherAppliance = Appliance::factory()->create(['household_id' => $otherHousehold->id]);

        MaintenanceTask::factory()->create([
            'appliance_id' => $otherAppliance->id,
            'name' => 'Other Household Task',
            'next_due_at' => now()->subDay(),
            'interval_unit' => 'months',
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')->assertDontSee('Other Household Task');
    }

    public function test_mark_done_rejects_foreign_household_task(): void
    {
        $otherHousehold = Household::factory()->create();
        $otherAppliance = Appliance::factory()->create(['household_id' => $otherHousehold->id]);

        $foreignTask = MaintenanceTask::factory()->create([
            'appliance_id' => $otherAppliance->id,
            'next_due_at' => now()->subDay(),
            'interval_unit' => 'months',
            'interval_value' => 6,
            'is_confirmed' => true,
        ]);

        try {
            Volt::test('pages.dashboard')->call('markDone', $foreignTask->id);
            $this->fail('ModelNotFoundException not thrown — forHousehold() scope guard may have been removed from markDone()');
        } catch (ModelNotFoundException $e) {
            // correct: forHousehold()->findOrFail() blocked the foreign task
        }

        $this->assertDatabaseMissing('service_records', [
            'maintenance_task_id' => $foreignTask->id,
        ]);
    }

    public function test_never_done_shown_on_dashboard_for_task_with_no_completion(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'next_due_at' => now()->addMonths(2),
            'interval_unit' => 'months',
            'last_completed_at' => null,
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')->assertSee('Never done');
    }

    public function test_last_done_shown_on_dashboard_for_task_with_completion(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'next_due_at' => now()->addMonths(4),
            'interval_unit' => 'months',
            'last_completed_at' => now()->subMonths(2),
            'is_confirmed' => true,
        ]);

        $this->get('/dashboard')->assertSee('Last done');
    }

    public function test_mark_done_creates_service_record_and_updates_task(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Overdue Task',
            'next_due_at' => now()->subDay(),
            'interval_unit' => 'months',
            'interval_value' => 6,
            'is_confirmed' => true,
        ]);

        Volt::test('pages.dashboard')
            ->call('markDone', $task->id)
            ->assertOk()
            ->assertSee('No overdue tasks.');

        $this->assertDatabaseHas('service_records', [
            'maintenance_task_id' => $task->id,
        ]);

        $task->refresh();
        $this->assertTrue($task->last_completed_at->isToday());
    }
}
