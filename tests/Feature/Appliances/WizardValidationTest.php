<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WizardValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_step1_requires_name(): void
    {
        $user      = User::factory()->create();
        $household = Household::factory()->create();
        $user->households()->attach($household->id, ['role' => 'owner']);

        $this->actingAs($user);

        Volt::test('pages.appliances.create')
            ->set('name', '')
            ->call('nextStep')
            ->assertHasErrors(['name'])
            ->assertSet('step', 1);
    }

    public function test_step1_requires_model(): void
    {
        $user      = User::factory()->create();
        $household = Household::factory()->create();
        $user->households()->attach($household->id, ['role' => 'owner']);

        $this->actingAs($user);

        Volt::test('pages.appliances.create')
            ->set('name', 'Washer')
            ->set('model', '')
            ->call('nextStep')
            ->assertHasErrors(['model'])
            ->assertSet('step', 1);
    }

    public function test_step1_requires_type(): void
    {
        $user      = User::factory()->create();
        $household = Household::factory()->create();
        $user->households()->attach($household->id, ['role' => 'owner']);

        $this->actingAs($user);

        Volt::test('pages.appliances.create')
            ->set('name', 'Washer')
            ->set('model', 'WM500')
            ->set('typeSearch', '')
            ->call('nextStep')
            ->assertHasErrors(['typeSearch'])
            ->assertSet('step', 1);
    }
}
