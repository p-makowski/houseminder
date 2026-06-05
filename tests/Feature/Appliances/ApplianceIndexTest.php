<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Appliance;
use App\Models\ApplianceType;
use App\Models\Household;
use App\Models\User;
use Livewire\Volt\Volt;

class ApplianceIndexTest extends ApplianceTestCase
{
    public function test_authenticated_user_sees_their_appliances(): void
    {
        $type = ApplianceType::factory()->create(['household_id' => null]);
        $appliance = Appliance::factory()->create([
            'household_id' => $this->household->id,
            'appliance_type_id' => $type->id,
            'name' => 'Samsung Washer',
        ]);

        Volt::test('pages.appliances.index')
            ->assertSee('Samsung Washer');
    }

    public function test_user_does_not_see_other_household_appliances(): void
    {
        $otherHousehold = Household::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->households()->attach($otherHousehold->id, ['role' => 'owner']);

        $type = ApplianceType::factory()->create(['household_id' => null]);

        Appliance::factory()->create([
            'household_id' => $this->household->id,
            'appliance_type_id' => $type->id,
            'name' => 'My Washer',
        ]);
        Appliance::factory()->create([
            'household_id' => $otherHousehold->id,
            'appliance_type_id' => $type->id,
            'name' => 'Other Washer',
        ]);

        // Our user sees only their own appliance
        Volt::test('pages.appliances.index')
            ->assertSee('My Washer')
            ->assertDontSee('Other Washer');

        // Other user sees only their own appliance
        $this->actingAs($otherUser);
        Volt::test('pages.appliances.index')
            ->assertSee('Other Washer')
            ->assertDontSee('My Washer');
    }
}
