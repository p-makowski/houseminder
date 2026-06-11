<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Appliance;
use App\Models\MaintenanceTask;
use App\Models\ServiceRecord;
use App\Models\User;
use Livewire\Volt\Volt;

class ApplianceShowTaskCreateTest extends ApplianceTestCase
{
    private Appliance $appliance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appliance = Appliance::factory()->create(['household_id' => $this->household->id]);
    }

    public function test_can_create_calendar_task_without_backdate(): void
    {
        $component = Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('startAddTask')
            ->set('addName', 'Filter check')
            ->set('addIntervalValue', 3)
            ->set('addIntervalUnit', 'months')
            ->call('saveNewTask');

        $task = MaintenanceTask::where('appliance_id', $this->appliance->id)->first();
        $this->assertNotNull($task);
        $this->assertSame('Filter check', $task->name);
        $this->assertTrue($task->is_confirmed);
        $this->assertNotNull($task->next_due_at);
        $this->assertNull($task->last_completed_at);
        $this->assertSame(0, $task->serviceRecords()->count());

        $component->assertSet('addingTask', false)
            ->assertSet('addName', '');
    }

    public function test_can_create_calendar_task_with_backdate_and_notes(): void
    {
        $backdate = now()->subMonths(6)->toDateString();

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('startAddTask')
            ->set('addName', 'Oil change')
            ->set('addIntervalValue', 6)
            ->set('addIntervalUnit', 'months')
            ->set('addLastDoneAt', $backdate)
            ->set('addNotes', 'Replaced filter')
            ->call('saveNewTask');

        $task = MaintenanceTask::where('appliance_id', $this->appliance->id)->first();
        $this->assertNotNull($task);
        $this->assertSame($backdate, $task->last_completed_at->toDateString());
        $this->assertSame(
            now()->subMonths(6)->addMonths(6)->toDateString(),
            $task->next_due_at->toDateString()
        );

        $record = $task->serviceRecords()->first();
        $this->assertNotNull($record);
        $this->assertSame('Replaced filter', $record->notes);
    }

    public function test_can_create_metric_task_with_metric_reading(): void
    {
        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('startAddTask')
            ->set('addName', 'Mileage service')
            ->set('addIntervalValue', 5000)
            ->set('addIntervalUnit', 'km')
            ->set('addIntervalCategory', 'metric')
            ->set('addLastMetric', '42000')
            ->call('saveNewTask');

        $task = MaintenanceTask::where('appliance_id', $this->appliance->id)->first();
        $this->assertNotNull($task);
        $this->assertNull($task->next_due_at);
        $this->assertTrue($task->is_confirmed);

        $record = $task->serviceRecords()->first();
        $this->assertNotNull($record);
        $this->assertSame(42000.0, (float) $record->metric_reading);
    }

    public function test_validation_rejects_invalid_data(): void
    {
        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('startAddTask')
            ->set('addName', '')
            ->set('addIntervalValue', 0)
            ->call('saveNewTask')
            ->assertHasErrors(['addName', 'addIntervalValue']);

        $this->assertSame(0, $this->appliance->maintenanceTasks()->count());
        $this->assertSame(0, ServiceRecord::whereHas('maintenanceTask', fn ($q) => $q->where('appliance_id', $this->appliance->id))->count());
    }

    public function test_unauthorized_user_cannot_create_task(): void
    {
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->assertForbidden();
    }
}
