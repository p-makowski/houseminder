<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Appliance;
use App\Models\ApplianceType;
use App\Models\MaintenanceTask;
use Livewire\Volt\Volt;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

class AddApplianceWizardTest extends ApplianceTestCase
{
    public function test_happy_path_creates_appliance_and_tasks(): void
    {
        $type = ApplianceType::factory()->create(['name' => 'Washing Machine', 'household_id' => null]);

        $fake = Prism::fake([
            StructuredResponseFake::make()
                ->withStructured([
                    'tasks' => [
                        [
                            'name'           => 'Clean filter',
                            'description'    => 'Remove and clean the lint filter.',
                            'interval_value' => 3,
                            'interval_unit'  => 'months',
                        ],
                        [
                            'name'           => 'Descale drum',
                            'description'    => 'Run a descaling cycle.',
                            'interval_value' => 6,
                            'interval_unit'  => 'months',
                        ],
                    ],
                ])
                ->withUsage(new Usage(120, 80)),
        ]);

        $component = Volt::test('pages.appliances.create')
            ->set('name', 'Test Washer')
            ->set('model', 'WM500')
            ->set('typeSearch', 'Washing Machine')
            ->set('selectedTypeId', $type->id)
            ->call('nextStep'); // step 1 → 2, aiLoading = true

        $component->call('fetchSuggestions');

        $component->assertSet('tasks', fn($tasks) => count($tasks) === 2);

        $component
            ->call('nextStep') // step 2 → 3
            ->call('nextStep') // step 3 → 4
            ->call('confirm');

        $this->assertTrue(
            Appliance::where('name', 'Test Washer')
                ->where('is_plan_confirmed', true)
                ->exists()
        );

        $appliance = Appliance::where('name', 'Test Washer')->first();
        $this->assertSame(2, MaintenanceTask::where('appliance_id', $appliance->id)->where('is_confirmed', true)->count());

        $fake->assertCallCount(1);
    }
}
