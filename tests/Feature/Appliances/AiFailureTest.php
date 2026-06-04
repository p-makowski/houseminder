<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Actions\GenerateMaintenancePlan;
use App\Models\ApplianceType;
use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class AiFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_failure_shows_error_and_retry_succeeds(): void
    {
        $user      = User::factory()->create();
        $household = Household::factory()->create();
        $user->households()->attach($household->id, ['role' => 'owner']);

        $type = ApplianceType::factory()->create(['name' => 'Washing Machine', 'household_id' => null]);

        $callCount = 0;

        $this->instance(GenerateMaintenancePlan::class, function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new PrismException('AI service unavailable');
            }

            return [
                [
                    'name'           => 'Clean filter',
                    'description'    => 'Remove and clean the lint filter.',
                    'interval_value' => 3,
                    'interval_unit'  => 'months',
                ],
            ];
        });

        $this->actingAs($user);

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
