<?php

declare(strict_types=1);

namespace Tests\Feature\Components;

use App\Models\Appliance;
use App\Models\Household;
use App\Models\MaintenanceTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceTaskCardTest extends TestCase
{
    use RefreshDatabase;

    private Appliance $appliance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        $user = User::factory()->create();
        $household = Household::factory()->create();
        $user->households()->attach($household->id, ['role' => 'owner']);
        $this->actingAs($user);

        $this->appliance = Appliance::factory()->create(['household_id' => $household->id]);
    }

    public function test_renders_task_name_and_shows_never_done_when_no_completion(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Oil change',
            'interval_value' => 3,
            'interval_unit' => 'months',
            'last_completed_at' => null,
        ]);

        $view = $this->blade(
            '<x-maintenance-task-card :task="$task" color="gray" />',
            compact('task')
        );

        $view->assertSee('Oil change');
        $view->assertSee('Every 3 months');
        $view->assertSee('Never done');
    }

    public function test_shows_last_done_with_tooltip_when_task_has_completion(): void
    {
        $completedAt = now()->subMonths(2);

        $task = MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'last_completed_at' => $completedAt,
        ]);

        $view = $this->blade(
            '<x-maintenance-task-card :task="$task" color="gray" />',
            compact('task')
        );

        $view->assertSee('Last done');
        $view->assertSee($completedAt->format('M j, Y'));
        $view->assertDontSee('Never done');
    }

    public function test_shows_draft_badge_for_unconfirmed_task_when_flag_enabled(): void
    {
        $task = MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'is_confirmed' => false,
        ]);

        $view = $this->blade(
            '<x-maintenance-task-card :task="$task" color="gray" :showDraftBadge="true" />',
            compact('task')
        );

        $view->assertSee('Draft');
    }

    public function test_color_prop_maps_to_correct_border_class(): void
    {
        $task = MaintenanceTask::factory()->create(['appliance_id' => $this->appliance->id]);

        $redView = $this->blade(
            '<x-maintenance-task-card :task="$task" color="red" />',
            compact('task')
        );
        $redView->assertSee('border-red-200');

        $yellowView = $this->blade(
            '<x-maintenance-task-card :task="$task" color="yellow" />',
            compact('task')
        );
        $yellowView->assertSee('border-yellow-200');
    }

    public function test_renders_appliance_name_prefix_when_show_appliance_name_is_true(): void
    {
        $this->appliance->update(['name' => 'Boiler']);
        $task = MaintenanceTask::factory()->create([
            'appliance_id' => $this->appliance->id,
            'name' => 'Annual service',
        ]);
        $task->load('appliance');

        $view = $this->blade(
            '<x-maintenance-task-card :task="$task" color="gray" :showApplianceName="true" />',
            compact('task')
        );

        $view->assertSee('Boiler — Annual service');
    }
}
