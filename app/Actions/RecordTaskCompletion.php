<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\MaintenanceTask;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordTaskCompletion
{
    public function __invoke(MaintenanceTask $task, User $user): void
    {
        $task->loadMissing('appliance');

        $household = $user->households()->first();
        abort_if(! $household || $task->appliance->household_id !== $household->id, 403);

        $completedAt = now();

        DB::transaction(function () use ($task, $completedAt): void {
            ServiceRecord::create([
                'maintenance_task_id' => $task->id,
                'completed_at' => $completedAt,
            ]);

            $task->last_completed_at = $completedAt;

            $task->next_due_at = match ($task->interval_unit) {
                'days' => $completedAt->copy()->addDays((int) $task->interval_value),
                'weeks' => $completedAt->copy()->addWeeks((int) $task->interval_value),
                'months' => $completedAt->copy()->addMonths((int) $task->interval_value),
                'years' => $completedAt->copy()->addYears((int) $task->interval_value),
                default => $task->next_due_at,
            };

            $task->save();
        });
    }
}
