<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Appliance;
use App\Models\ApplianceType;
use App\Models\Household;
use Livewire\Volt\Volt;

class ApplianceEditTest extends ApplianceTestCase
{
    public function test_authenticated_user_can_update_appliance(): void
    {
        $type = ApplianceType::factory()->create(['household_id' => null]);
        $appliance = Appliance::factory()->create([
            'household_id'      => $this->household->id,
            'appliance_type_id' => $type->id,
            'name'              => 'Old Name',
            'model'             => 'Old Model',
        ]);

        Volt::test('pages.appliances.edit', ['appliance' => $appliance])
            ->set('name', 'New Name')
            ->set('model', 'New Model')
            ->call('save');

        $appliance->refresh();
        $this->assertSame('New Name', $appliance->name);
        $this->assertSame('New Model', $appliance->model);
    }

    public function test_save_with_cross_household_type_id_returns_403(): void
    {
        $otherHousehold = Household::factory()->create();
        $ownType = ApplianceType::factory()->create(['household_id' => null]);
        $privateType = ApplianceType::factory()->create(['household_id' => $otherHousehold->id]);

        $appliance = Appliance::factory()->create([
            'household_id'      => $this->household->id,
            'appliance_type_id' => $ownType->id,
        ]);

        Volt::test('pages.appliances.edit', ['appliance' => $appliance])
            ->set('selectedTypeId', $privateType->id)
            ->set('typeSearch', $privateType->name)
            ->call('save')
            ->assertForbidden();
    }

    public function test_editing_appliance_from_another_household_returns_403(): void
    {
        $otherHousehold = Household::factory()->create();
        $type = ApplianceType::factory()->create(['household_id' => null]);
        $appliance = Appliance::factory()->create([
            'household_id'      => $otherHousehold->id,
            'appliance_type_id' => $type->id,
        ]);

        Volt::test('pages.appliances.edit', ['appliance' => $appliance])
            ->assertForbidden();
    }
}
