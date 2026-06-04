<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Appliance;
use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class DashboardTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Household $household;
    protected Appliance $appliance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->household = Household::factory()->create();
        $this->user->households()->attach($this->household->id, ['role' => 'owner']);
        $this->actingAs($this->user);

        $this->appliance = Appliance::factory()->create([
            'household_id' => $this->household->id,
        ]);
    }
}
