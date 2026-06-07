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

    public function test_due_soon_task_renders_yellow_border(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'next_due_at'  => now()->addDays(3),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->assertSee('border-yellow-200');
    }

    public function test_tasks_sorted_by_name_alphabetically(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'Zap filter',
            'next_due_at'  => now()->addMonths(1),
        ]);
        MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name'         => 'Alpha check',
            'next_due_at'  => now()->addMonths(2),
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->call('setSortBy', 'name')
            ->assertSeeInOrder(['Alpha check', 'Zap filter']);
    }

    public function test_metric_task_renders_gray_border(): void
    {
        MaintenanceTask::factory()->create([
            'appliance_id'  => $this->appliance->id,
            'interval_unit' => 'km',
            'next_due_at'   => null,
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $this->appliance])
            ->assertSee('border-gray-200');
    }
}
