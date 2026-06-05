<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Appliance;
use App\Models\Household;
use Livewire\Volt\Volt;

class ApplianceShowTest extends ApplianceTestCase
{
    public function test_authenticated_user_can_view_appliance(): void
    {
        $appliance = Appliance::factory()->create([
            'household_id' => $this->household->id,
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $appliance])
            ->assertOk()
            ->assertSee($appliance->name);
    }

    public function test_viewing_appliance_from_another_household_returns_403(): void
    {
        $otherHousehold = Household::factory()->create();
        $appliance = Appliance::factory()->create([
            'household_id' => $otherHousehold->id,
        ]);

        Volt::test('pages.appliances.show', ['appliance' => $appliance])
            ->assertForbidden();
    }
}
