<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Appliance;
use App\Models\Household;
use App\Models\MaintenanceTask;
use App\Models\ServiceRecord;
use Livewire\Volt\Volt;

class ApplianceShowTaskDeleteTest extends ApplianceTestCase
{
    private Appliance $appliance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appliance = Appliance::factory()->create(['household_id' => $this->household->id]);
    }

    public function test_delete_task_removes_task_and_cascades_service_records(): void
    {
        $task   = MaintenanceTask::factory()->create(['appliance_id' => $this->appliance->id]);
        $record = ServiceRecord::factory()->create(['maintenance_task_id' => $task->id]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('confirmDelete', $task->id)
            ->call('deleteTask');

        $this->assertDatabaseMissing('maintenance_tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('service_records', ['id' => $record->id]);
    }

    public function test_delete_task_without_confirm_delete_is_a_no_op(): void
    {
        $task = MaintenanceTask::factory()->create(['appliance_id' => $this->appliance->id]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('deleteTask');

        $this->assertDatabaseHas('maintenance_tasks', ['id' => $task->id]);
    }

    public function test_confirm_delete_on_task_from_different_household_returns_403(): void
    {
        $otherHousehold = Household::factory()->create();
        $otherAppliance = Appliance::factory()->create(['household_id' => $otherHousehold->id]);
        $foreignTask    = MaintenanceTask::factory()->create(['appliance_id' => $otherAppliance->id]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('confirmDelete', $foreignTask->id)
            ->assertForbidden();

        $this->assertDatabaseHas('maintenance_tasks', ['id' => $foreignTask->id]);
    }
}
