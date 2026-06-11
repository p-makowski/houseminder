<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\ApplianceType;
use App\Models\MaintenanceTask;
use Livewire\Volt\Volt;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

class TaskEditingTest extends ApplianceTestCase
{
    public function test_task_editing_delete_and_add_reflect_in_db(): void
    {
        $type = ApplianceType::factory()->create(['name' => 'Washer', 'household_id' => null]);

        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured([
                    'tasks' => [
                        [
                            'name' => 'Clean filter',
                            'description' => 'Remove and clean the lint filter.',
                            'interval_value' => 3,
                            'interval_unit' => 'months',
                        ],
                        [
                            'name' => 'Descale drum',
                            'description' => 'Run a descaling cycle.',
                            'interval_value' => 6,
                            'interval_unit' => 'months',
                        ],
                    ],
                ])
                ->withUsage(new Usage(100, 60)),
        ]);

        $component = Volt::test('pages.appliances.create')
            ->set('name', 'Edit Washer')
            ->set('model', 'EW100')
            ->set('typeSearch', 'Washer')
            ->set('selectedTypeId', $type->id)
            ->call('nextStep'); // step 1 → 2

        $component->call('fetchSuggestions');

        $component
            ->set('tasks.0.name', 'Custom Name')
            ->set('tasks.0.interval_value', 12);

        $component->call('deleteTask', 1);
        $component->assertSet('tasks', fn ($t) => count($t) === 1);

        $component->call('addTask');
        $component->assertSet('tasks', fn ($t) => count($t) === 2);

        $component->set('tasks.1.name', 'Manual Task');

        $component
            ->call('nextStep') // step 2 → 3
            ->call('nextStep') // step 3 → 4
            ->call('confirm');

        $this->assertTrue(
            MaintenanceTask::where('name', 'Custom Name')
                ->where('interval_value', 12)
                ->exists()
        );

        $applianceId = MaintenanceTask::where('name', 'Custom Name')->value('appliance_id');
        $this->assertSame(2, MaintenanceTask::where('appliance_id', $applianceId)->count());
    }
}
