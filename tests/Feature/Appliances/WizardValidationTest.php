<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use Livewire\Volt\Volt;

class WizardValidationTest extends ApplianceTestCase
{
    public function test_step1_requires_name(): void
    {
        Volt::test('pages.appliances.create')
            ->set('name', '')
            ->call('nextStep')
            ->assertHasErrors(['name'])
            ->assertSet('step', 1);
    }

    public function test_step1_requires_model(): void
    {
        Volt::test('pages.appliances.create')
            ->set('name', 'Washer')
            ->set('model', '')
            ->call('nextStep')
            ->assertHasErrors(['model'])
            ->assertSet('step', 1);
    }

    public function test_step1_requires_type(): void
    {
        Volt::test('pages.appliances.create')
            ->set('name', 'Washer')
            ->set('model', 'WM500')
            ->set('typeSearch', '')
            ->call('nextStep')
            ->assertHasErrors(['typeSearch'])
            ->assertSet('step', 1);
    }
}
