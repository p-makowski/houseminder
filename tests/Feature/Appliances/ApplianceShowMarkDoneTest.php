<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Appliance;
use App\Models\Household;
use App\Models\MaintenanceTask;
use Livewire\Volt\Volt;

class ApplianceShowMarkDoneTest extends ApplianceTestCase
{
    private Appliance $appliance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appliance = Appliance::factory()->create(['household_id' => $this->household->id]);
    }

    public function test_mark_done_on_calendar_task_creates_service_record_and_advances_due_date(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'interval_value' => 3,
            'interval_unit' => 'months',
            'next_due_at' => now()->subDay(),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('markDone', $task->id);

        $this->assertDatabaseHas('service_records', ['maintenance_task_id' => $task->id]);

        $task->refresh();
        $this->assertTrue($task->next_due_at->isAfter(now()));
    }

    public function test_mark_done_on_metric_task_creates_service_record_without_changing_next_due_at(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'interval_unit' => 'km',
            'next_due_at' => null,
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('markDone', $task->id);

        $this->assertDatabaseHas('service_records', ['maintenance_task_id' => $task->id]);

        $task->refresh();
        $this->assertNull($task->next_due_at);
    }

    public function test_mark_done_on_task_from_different_appliance_returns_403(): void
    {
        $otherHousehold = Household::factory()->create();
        $otherAppliance = Appliance::factory()->create(['household_id' => $otherHousehold->id]);
        $foreignTask = MaintenanceTask::factory()->create(['appliance_id' => $otherAppliance->id]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('markDone', $foreignTask->id)
            ->assertForbidden();

        $this->assertDatabaseMissing('service_records', ['maintenance_task_id' => $foreignTask->id]);
    }
}
