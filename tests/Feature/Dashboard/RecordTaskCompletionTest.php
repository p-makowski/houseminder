<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Actions\RecordTaskCompletion;
use App\Models\Appliance;
use App\Models\Household;
use App\Models\MaintenanceTask;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordTaskCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Household $household;
    protected Appliance $appliance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->household = Household::factory()->create();
        $this->user->households()->attach($this->household->id, ['role' => 'owner']);
        $this->actingAs($this->user);

        $this->appliance = Appliance::factory()->create([
            'household_id' => $this->household->id,
        ]);
    }

    public function test_creates_service_record_with_completed_at_now(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id'  => $this->appliance->id,
            'interval_unit' => 'months',
            'interval_value' => 6,
            'next_due_at'   => now()->subDay(),
            'is_confirmed'  => true,
        ]);

        (new RecordTaskCompletion)->execute($task, $this->user);

        $this->assertDatabaseHas('service_records', [
            'maintenance_task_id' => $task->id,
        ]);

        $record = ServiceRecord::where('maintenance_task_id', $task->id)->latest()->first();
        $this->assertNotNull($record);
        $this->assertTrue($record->completed_at->isToday());
    }

    public function test_updates_last_completed_at_on_task(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id'   => $this->appliance->id,
            'interval_unit'  => 'months',
            'interval_value' => 6,
            'next_due_at'    => now()->subDay(),
            'is_confirmed'   => true,
        ]);

        (new RecordTaskCompletion)->execute($task, $this->user);

        $task->refresh();
        $this->assertTrue($task->last_completed_at->isToday());
    }

    public function test_recalculates_next_due_at_for_days(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id'   => $this->appliance->id,
            'interval_unit'  => 'days',
            'interval_value' => 30,
            'next_due_at'    => now()->subDay(),
            'is_confirmed'   => true,
        ]);

        (new RecordTaskCompletion)->execute($task, $this->user);

        $task->refresh();
        $this->assertTrue($task->next_due_at->isFuture());
        $this->assertEquals(now()->addDays(30)->toDateString(), $task->next_due_at->toDateString());
    }

    public function test_recalculates_next_due_at_for_weeks(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id'   => $this->appliance->id,
            'interval_unit'  => 'weeks',
            'interval_value' => 2,
            'next_due_at'    => now()->subDay(),
            'is_confirmed'   => true,
        ]);

        (new RecordTaskCompletion)->execute($task, $this->user);

        $task->refresh();
        $this->assertEquals(now()->addWeeks(2)->toDateString(), $task->next_due_at->toDateString());
    }

    public function test_recalculates_next_due_at_for_months(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id'   => $this->appliance->id,
            'interval_unit'  => 'months',
            'interval_value' => 6,
            'next_due_at'    => now()->subDay(),
            'is_confirmed'   => true,
        ]);

        (new RecordTaskCompletion)->execute($task, $this->user);

        $task->refresh();
        $this->assertEquals(now()->addMonths(6)->toDateString(), $task->next_due_at->toDateString());
    }

    public function test_recalculates_next_due_at_for_years(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id'   => $this->appliance->id,
            'interval_unit'  => 'years',
            'interval_value' => 1,
            'next_due_at'    => now()->subDay(),
            'is_confirmed'   => true,
        ]);

        (new RecordTaskCompletion)->execute($task, $this->user);

        $task->refresh();
        $this->assertEquals(now()->addYear()->toDateString(), $task->next_due_at->toDateString());
    }

    public function test_does_not_change_next_due_at_for_metric_task(): void
    {
        $originalNextDue = now()->addMonths(3);

        $task = MaintenanceTask::factory()->create([
            'appliance_id'   => $this->appliance->id,
            'interval_unit'  => 'hours',
            'interval_value' => 500,
            'next_due_at'    => $originalNextDue,
            'is_confirmed'   => true,
        ]);

        (new RecordTaskCompletion)->execute($task, $this->user);

        $task->refresh();
        $this->assertEquals($originalNextDue->toDateString(), $task->next_due_at->toDateString());
    }

    public function test_aborts_403_for_task_belonging_to_different_household(): void
    {
        $otherHousehold = Household::factory()->create();
        $otherAppliance = Appliance::factory()->create([
            'household_id' => $otherHousehold->id,
        ]);

        $task = MaintenanceTask::factory()->create([
            'appliance_id'   => $otherAppliance->id,
            'interval_unit'  => 'months',
            'interval_value' => 6,
            'next_due_at'    => now()->subDay(),
            'is_confirmed'   => true,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        (new RecordTaskCompletion)->execute($task, $this->user);
    }
}
