<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use Livewire\Volt\Volt;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

/** Tests AI response shape via Prism::fake() — exercises real GenerateMaintenancePlan validation. For transport failures (PrismException), see AiFailureTest. */
class AiContractTest extends ApplianceTestCase
{
    public function test_zero_tasks_shows_immediate_error(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['tasks' => []])
                ->withUsage(new Usage(10, 5)),
        ]);

        $component = Volt::test('pages.appliances.create')
            ->set('name', 'Test Washer')
            ->set('model', 'WM500')
            ->set('typeSearch', 'Washing Machine');

        $component->call('fetchSuggestions');

        $component
            ->assertSet('aiError', 'No maintenance tasks were generated. Please try again.')
            ->assertSet('aiLoading', false);
    }

    public function test_missing_required_field_shows_error(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured([
                    'tasks' => [
                        [
                            'description' => 'Clean the lint filter.',
                            'interval_value' => 3,
                            'interval_unit' => 'months',
                            // 'name' is intentionally omitted to trigger field validation
                        ],
                    ],
                ])
                ->withUsage(new Usage(10, 5)),
        ]);

        $component = Volt::test('pages.appliances.create')
            ->set('name', 'Test Washer')
            ->set('model', 'WM500')
            ->set('typeSearch', 'Washing Machine');

        $component->call('fetchSuggestions');

        $component
            ->assertSet('aiError', fn ($v) => ! empty($v))
            ->assertSet('aiLoading', false);
    }

    public function test_throwable_fallback_shows_error(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['tasks' => 'not-an-array']) // strings are not iterable in PHP 8 — triggers TypeError → \Throwable catch in fetchSuggestions()
                ->withUsage(new Usage(10, 5)),
        ]);

        $component = Volt::test('pages.appliances.create')
            ->set('name', 'Test Washer')
            ->set('model', 'WM500')
            ->set('typeSearch', 'Washing Machine');

        $component->call('fetchSuggestions');

        $component
            ->assertSet('aiError', 'An unexpected error occurred. Please try again.')
            ->assertSet('aiLoading', false);
    }
}
