<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Appliance;
use App\Models\ApplianceType;
use App\Models\Household;
use App\Models\MaintenanceTask;
use App\Models\User;
use Livewire\Volt\Volt;

class ApplianceDeleteTest extends ApplianceTestCase
{
    public function test_authenticated_user_can_delete_their_appliance(): void
    {
        $type = ApplianceType::factory()->create(['household_id' => null]);
        $appliance = Appliance::factory()->create([
            'household_id'      => $this->household->id,
            'appliance_type_id' => $type->id,
        ]);
        $task = MaintenanceTask::factory()->create(['appliance_id' => $appliance->id]);

        Volt::test('pages.appliances.edit', ['appliance' => $appliance])
            ->call('delete');

        $this->assertDatabaseMissing('appliances', ['id' => $appliance->id]);
        $this->assertDatabaseMissing('maintenance_tasks', ['id' => $task->id]);
    }

    public function test_delete_re_auth_blocks_after_household_access_revoked(): void
    {
        $type = ApplianceType::factory()->create(['household_id' => null]);
        $appliance = Appliance::factory()->create([
            'household_id'      => $this->household->id,
            'appliance_type_id' => $type->id,
        ]);

        $component = Volt::test('pages.appliances.edit', ['appliance' => $appliance]);

        // Revoke household access between mount and delete
        $this->user->households()->detach($this->household->id);

        $component->call('delete')->assertForbidden();

        $this->assertDatabaseHas('appliances', ['id' => $appliance->id]);
    }

    public function test_deleting_appliance_from_another_household_returns_403(): void
    {
        $otherHousehold = Household::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->households()->attach($otherHousehold->id, ['role' => 'owner']);

        $type = ApplianceType::factory()->create(['household_id' => null]);
        $appliance = Appliance::factory()->create([
            'household_id'      => $otherHousehold->id,
            'appliance_type_id' => $type->id,
        ]);

        Volt::test('pages.appliances.edit', ['appliance' => $appliance])
            ->assertForbidden();

        $this->assertDatabaseHas('appliances', ['id' => $appliance->id]);
    }
}
