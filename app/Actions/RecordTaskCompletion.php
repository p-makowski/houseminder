<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\MaintenanceTask;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordTaskCompletion
{
    public function execute(MaintenanceTask $task, User $user): void
    {
        $household = $user->households()->first();
        abort_if(! $household || $task->appliance->household_id !== $household->id, 403);

        DB::transaction(function () use ($task): void {
            ServiceRecord::create([
                'maintenance_task_id' => $task->id,
                'completed_at' => now(),
            ]);

            $task->last_completed_at = now();

            $task->next_due_at = match ($task->interval_unit) {
                'days' => now()->addDays((int) $task->interval_value),
                'weeks' => now()->addWeeks((int) $task->interval_value),
                'months' => now()->addMonths((int) $task->interval_value),
                'years' => now()->addYears((int) $task->interval_value),
                default => $task->next_due_at,
            };

            $task->save();
        });
    }
}
