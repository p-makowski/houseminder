<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Appliance;
use App\Models\MaintenanceTask;
use Livewire\Volt\Volt;

class ApplianceShowDisplayTest extends ApplianceTestCase
{
    private Appliance $appliance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appliance = Appliance::factory()->create(['household_id' => $this->household->id]);
    }

    public function test_overdue_task_renders_red_border(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'next_due_at'  => now()->subDay(),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->assertSee('border-red-200');
    }

    public function test_tasks_sorted_by_name_alphabetically_within_section(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'Zap filter',
            'next_due_at'  => now()->addDays(10),
        ]);
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'Alpha check',
            'next_due_at'  => now()->addDays(20),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('setSortBy', 'name')
            ->assertSeeInOrder(['Alpha check', 'Zap filter']);
    }

    public function test_never_done_shown_for_task_with_no_completion_history(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id'      => $this->appliance->id,
            'last_completed_at' => null,
            'next_due_at'       => now()->addMonths(3),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->assertSee('Never done');
    }

    public function test_last_done_shown_for_task_with_completion_history(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id'      => $this->appliance->id,
            'last_completed_at' => now()->subMonths(2),
            'next_due_at'       => now()->addMonths(4),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->assertSee('Last done');
    }
}
