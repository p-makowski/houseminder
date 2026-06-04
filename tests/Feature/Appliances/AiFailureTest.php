<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Actions\GenerateMaintenancePlan;
use App\Models\ApplianceType;
use Livewire\Volt\Volt;
use Prism\Prism\Exceptions\PrismException;

class AiFailureTest extends ApplianceTestCase
{
    public function test_ai_failure_shows_error_and_retry_succeeds(): void
    {
        $type = ApplianceType::factory()->create(['name' => 'Washing Machine', 'household_id' => null]);

        $mock = $this->mock(GenerateMaintenancePlan::class);
        $mock->shouldReceive('__invoke')
            ->once()
            ->andThrow(new PrismException('AI service unavailable'));
        $mock->shouldReceive('__invoke')
            ->once()
            ->andReturn([
                [
                    'name'           => 'Clean filter',
                    'description'    => 'Remove and clean the lint filter.',
                    'interval_value' => 3,
                    'interval_unit'  => 'months',
                ],
            ]);

        $component = Volt::test('pages.appliances.create')
            ->set('name', 'Test Washer')
            ->set('model', 'WM500')
            ->set('typeSearch', 'Washing Machine')
            ->set('selectedTypeId', $type->id)
            ->call('nextStep'); // step 1 → 2

        $component->call('fetchSuggestions');

        $component
            ->assertSet('aiError', fn($e) => !is_null($e))
            ->assertSet('aiLoading', false);

        $component->call('retryFetch');

        $component
            ->assertSet('tasks', fn($t) => count($t) > 0)
            ->assertSet('aiError', null);
    }
}
