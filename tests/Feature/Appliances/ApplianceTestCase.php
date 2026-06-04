<?php

declare(strict_types=1);

namespace Tests\Feature\Appliances;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ApplianceTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->household = Household::factory()->create();
        $this->user->households()->attach($this->household->id, ['role' => 'owner']);
        $this->actingAs($this->user);
    }
}
