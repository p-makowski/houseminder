<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Appliance;
use App\Models\Household;
use App\Models\MaintenanceTask;
use Livewire\Volt\Volt;

class ApplianceShowTaskEditTest extends ApplianceTestCase
{
    private Appliance $appliance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appliance = Appliance::factory()->create(['household_id' => $this->household->id]);
    }

    public function test_save_edit_persists_name_and_description(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Old name',
            'description' => 'Old desc',
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('startEdit', $task->id)
            ->set('editName', 'New name')
            ->set('editDescription', 'New desc')
            ->call('saveEdit');

        $task->refresh();
        $this->assertSame('New name', $task->name);
        $this->assertSame('New desc', $task->description);
    }

    public function test_save_edit_on_calendar_task_recalculates_next_due_at_from_last_completed_at(): void
    {
        $lastDone = now()->subMonths(2);
        $task = MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'interval_value' => 6,
            'interval_unit' => 'months',
            'anchor_type' => 'from_last_done',
            'last_completed_at' => $lastDone,
            'next_due_at' => $lastDone->copy()->addMonths(6),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('startEdit', $task->id)
            ->set('editIntervalValue', 3)
            ->set('editIntervalUnit', 'months')
            ->set('editNextDueAt', '')
            ->call('saveEdit');

        $task->refresh();
        $this->assertSame(
            $lastDone->copy()->addMonths(3)->toDateString(),
            $task->next_due_at->toDateString()
        );
    }

    public function test_save_edit_with_explicit_next_due_at_skips_recalculation(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'interval_unit' => 'months',
        ]);

        $explicit = now()->addYear()->toDateString();

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('startEdit', $task->id)
            ->set('editNextDueAt', $explicit)
            ->call('saveEdit');

        $task->refresh();
        $this->assertSame($explicit, $task->next_due_at->toDateString());
    }

    public function test_save_edit_on_metric_task_does_not_alter_next_due_at(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'interval_unit' => 'km',
            'next_due_at' => null,
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('startEdit', $task->id)
            ->set('editName', 'Updated name')
            ->call('saveEdit');

        $task->refresh();
        $this->assertNull($task->next_due_at);
        $this->assertSame('Updated name', $task->name);
    }

    public function test_start_edit_on_task_from_different_appliance_returns_403(): void
    {
        $otherHousehold = Household::factory()->create();
        $otherAppliance = Appliance::factory()->create(['household_id' => $otherHousehold->id]);
        $foreignTask = MaintenanceTask::factory()->create(['appliance_id' => $otherAppliance->id]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('startEdit', $foreignTask->id)
            ->assertForbidden();
    }
}
