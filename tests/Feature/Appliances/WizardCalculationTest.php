<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\ApplianceType;
use App\Models\MaintenanceTask;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

class WizardCalculationTest extends ApplianceTestCase
{
    private const BACKDATE = '2024-01-15';

    // === No-backdate scenario: confirm() falls back to Carbon::today() as anchor (frozen by freezeTime()) ===

    public function test_confirm_next_due_at_for_days_with_no_backdate(): void
    {
        $task = $this->confirmedTask(['interval_unit' => 'days', 'interval_value' => 30, 'anchor_type' => 'from_last_done']);

        $this->assertSame(Carbon::today()->addDays(30)->toDateString(), $task->next_due_at->toDateString());
    }

    public function test_confirm_next_due_at_for_weeks_with_no_backdate(): void
    {
        $task = $this->confirmedTask(['interval_unit' => 'weeks', 'interval_value' => 2, 'anchor_type' => 'from_last_done']);

        $this->assertSame(Carbon::today()->addWeeks(2)->toDateString(), $task->next_due_at->toDateString());
    }

    public function test_confirm_next_due_at_for_months_with_no_backdate(): void
    {
        $task = $this->confirmedTask(['interval_unit' => 'months', 'interval_value' => 6, 'anchor_type' => 'from_last_done']);

        $this->assertSame(Carbon::today()->addMonths(6)->toDateString(), $task->next_due_at->toDateString());
    }

    public function test_confirm_next_due_at_for_years_with_no_backdate(): void
    {
        $task = $this->confirmedTask(['interval_unit' => 'years', 'interval_value' => 1, 'anchor_type' => 'from_last_done']);

        $this->assertSame(Carbon::today()->addYear()->toDateString(), $task->next_due_at->toDateString());
    }

    // === from_last_done + backdate (anchor = 2024-01-15) ===

    public function test_confirm_next_due_at_for_days_with_from_last_done_anchor(): void
    {
        $task = $this->confirmedTask(
            ['interval_unit' => 'days', 'interval_value' => 30, 'anchor_type' => 'from_last_done'],
            ['skip' => false, 'date' => self::BACKDATE, 'metric' => null, 'notes' => ''],
        );

        $this->assertSame('2024-02-14', $task->next_due_at->toDateString());
        $this->assertSame(self::BACKDATE, $task->last_completed_at->toDateString());
        $this->assertNull($task->anchor_date);
    }

    public function test_confirm_next_due_at_for_weeks_with_from_last_done_anchor(): void
    {
        $task = $this->confirmedTask(
            ['interval_unit' => 'weeks', 'interval_value' => 2, 'anchor_type' => 'from_last_done'],
            ['skip' => false, 'date' => self::BACKDATE, 'metric' => null, 'notes' => ''],
        );

        $this->assertSame('2024-01-29', $task->next_due_at->toDateString());
        $this->assertSame(self::BACKDATE, $task->last_completed_at->toDateString());
        $this->assertNull($task->anchor_date);
    }

    public function test_confirm_next_due_at_for_months_with_from_last_done_anchor(): void
    {
        $task = $this->confirmedTask(
            ['interval_unit' => 'months', 'interval_value' => 6, 'anchor_type' => 'from_last_done'],
            ['skip' => false, 'date' => self::BACKDATE, 'metric' => null, 'notes' => ''],
        );

        $this->assertSame('2024-07-15', $task->next_due_at->toDateString());
        $this->assertSame(self::BACKDATE, $task->last_completed_at->toDateString());
        $this->assertNull($task->anchor_date);
    }

    public function test_confirm_next_due_at_for_years_with_from_last_done_anchor(): void
    {
        $task = $this->confirmedTask(
            ['interval_unit' => 'years', 'interval_value' => 1, 'anchor_type' => 'from_last_done'],
            ['skip' => false, 'date' => self::BACKDATE, 'metric' => null, 'notes' => ''],
        );

        $this->assertSame('2025-01-15', $task->next_due_at->toDateString());
        $this->assertSame(self::BACKDATE, $task->last_completed_at->toDateString());
        $this->assertNull($task->anchor_date);
    }

    // === fixed_calendar + backdate (anchor = 2024-01-15) ===

    public function test_confirm_next_due_at_for_days_with_fixed_calendar_anchor(): void
    {
        $task = $this->confirmedTask(
            ['interval_unit' => 'days', 'interval_value' => 30, 'anchor_type' => 'fixed_calendar'],
            ['skip' => false, 'date' => self::BACKDATE, 'metric' => null, 'notes' => ''],
        );

        $this->assertSame('2024-02-14', $task->next_due_at->toDateString());
        $this->assertSame(self::BACKDATE, $task->anchor_date->toDateString());
        $this->assertNull($task->last_completed_at);
    }

    public function test_confirm_next_due_at_for_weeks_with_fixed_calendar_anchor(): void
    {
        $task = $this->confirmedTask(
            ['interval_unit' => 'weeks', 'interval_value' => 2, 'anchor_type' => 'fixed_calendar'],
            ['skip' => false, 'date' => self::BACKDATE, 'metric' => null, 'notes' => ''],
        );

        $this->assertSame('2024-01-29', $task->next_due_at->toDateString());
        $this->assertSame(self::BACKDATE, $task->anchor_date->toDateString());
        $this->assertNull($task->last_completed_at);
    }

    public function test_confirm_next_due_at_for_months_with_fixed_calendar_anchor(): void
    {
        $task = $this->confirmedTask(
            ['interval_unit' => 'months', 'interval_value' => 6, 'anchor_type' => 'fixed_calendar'],
            ['skip' => false, 'date' => self::BACKDATE, 'metric' => null, 'notes' => ''],
        );

        $this->assertSame('2024-07-15', $task->next_due_at->toDateString());
        $this->assertSame(self::BACKDATE, $task->anchor_date->toDateString());
        $this->assertNull($task->last_completed_at);
    }

    public function test_confirm_next_due_at_for_years_with_fixed_calendar_anchor(): void
    {
        $task = $this->confirmedTask(
            ['interval_unit' => 'years', 'interval_value' => 1, 'anchor_type' => 'fixed_calendar'],
            ['skip' => false, 'date' => self::BACKDATE, 'metric' => null, 'notes' => ''],
        );

        $this->assertSame('2025-01-15', $task->next_due_at->toDateString());
        $this->assertSame(self::BACKDATE, $task->anchor_date->toDateString());
        $this->assertNull($task->last_completed_at);
    }

    private function confirmedTask(array $taskFields, array $backdate = []): MaintenanceTask
    {
        $type = ApplianceType::factory()->create(['name' => 'Test Type', 'household_id' => null]);

        $taskFields = array_merge(['name' => 'Test Task', 'description' => null], $taskFields);
        $backdate = $backdate ?: ['skip' => false, 'date' => '', 'metric' => null, 'notes' => ''];

        Volt::test('pages.appliances.create')
            ->set('name', 'Test Appliance')
            ->set('model', 'Model X')
            ->set('selectedTypeId', $type->id)
            ->set('tasks', [$taskFields])
            ->set('backdates', [$backdate])
            ->call('confirm');

        return MaintenanceTask::first();
    }
}
