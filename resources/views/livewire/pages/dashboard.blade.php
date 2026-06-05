<?php

declare(strict_types=1);

use App\Actions\RecordTaskCompletion;
use App\Models\MaintenanceTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Collection $overdue;
    public Collection $dueThisWeek;
    public Collection $upcoming;
    public Collection $metric;

    public function mount(): void
    {
        $household = Auth::user()->households()->first();
        abort_if(! $household, 403);

        $this->loadTasks($household->id);
    }

    public function markDone(int $taskId): void
    {
        $household = Auth::user()->households()->first();
        abort_if(! $household, 403);

        $task = MaintenanceTask::calendar()->forHousehold($household->id)->findOrFail($taskId);

        (new RecordTaskCompletion)($task, Auth::user());

        $this->loadTasks($household->id);
    }

    private function loadTasks(int $householdId): void
    {
        $this->overdue = MaintenanceTask::calendar()
            ->forHousehold($householdId)
            ->where('next_due_at', '<', now())
            ->orderBy('next_due_at')
            ->with('appliance')
            ->get();

        $this->dueThisWeek = MaintenanceTask::calendar()
            ->forHousehold($householdId)
            ->whereBetween('next_due_at', [now(), now()->addDays(7)])
            ->orderBy('next_due_at')
            ->with('appliance')
            ->get();

        $this->upcoming = MaintenanceTask::calendar()
            ->forHousehold($householdId)
            ->where('next_due_at', '>', now()->addDays(7))
            ->orderBy('next_due_at')
            ->with('appliance')
            ->get();

        $this->metric = MaintenanceTask::metric()
            ->forHousehold($householdId)
            ->orderBy('name')
            ->with('appliance')
            ->get();
    }
}; ?>

<x-slot name="header">
    Dashboard
</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

        {{-- Overdue --}}
        <section>
            <h2 class="text-lg font-semibold text-red-700 mb-3">Overdue</h2>
            @if($overdue->isEmpty())
                <p class="text-sm text-gray-500">No overdue tasks.</p>
            @else
                <div class="space-y-2">
                    @foreach($overdue as $task)
                        <div class="bg-white border border-red-200 rounded-md px-4 py-3 flex justify-between items-center">
                            <div>
                                <p class="font-medium text-gray-900">{{ $task->appliance->name }} — {{ $task->name }}</p>
                                <p class="text-sm text-red-600">Due {{ $task->next_due_at->format('M j, Y') }}</p>
                            </div>
                            <button wire:click="markDone({{ $task->id }})" class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded">
                                Mark done
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Due this week --}}
        <section>
            <h2 class="text-lg font-semibold text-yellow-700 mb-3">Due this week</h2>
            @if($dueThisWeek->isEmpty())
                <p class="text-sm text-gray-500">Nothing due this week.</p>
            @else
                <div class="space-y-2">
                    @foreach($dueThisWeek as $task)
                        <div class="bg-white border border-yellow-200 rounded-md px-4 py-3 flex justify-between items-center">
                            <div>
                                <p class="font-medium text-gray-900">{{ $task->appliance->name }} — {{ $task->name }}</p>
                                <p class="text-sm text-yellow-600">Due {{ $task->next_due_at->format('M j, Y') }}</p>
                            </div>
                            <button wire:click="markDone({{ $task->id }})" class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded">
                                Mark done
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Upcoming --}}
        <section>
            <h2 class="text-lg font-semibold text-gray-700 mb-3">Upcoming</h2>
            @if($upcoming->isEmpty())
                <p class="text-sm text-gray-500">No upcoming tasks.</p>
            @else
                <div class="space-y-2">
                    @foreach($upcoming as $task)
                        <div class="bg-white border border-gray-200 rounded-md px-4 py-3 flex justify-between items-center">
                            <div>
                                <p class="font-medium text-gray-900">{{ $task->appliance->name }} — {{ $task->name }}</p>
                                <p class="text-sm text-gray-500">Due {{ $task->next_due_at->format('M j, Y') }}</p>
                            </div>
                            <button wire:click="markDone({{ $task->id }})" class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded">
                                Mark done
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Manual tracking --}}
        <section>
            <h2 class="text-lg font-semibold text-gray-700 mb-3">Manual tracking</h2>
            @if($metric->isEmpty())
                <p class="text-sm text-gray-500">No tasks requiring manual tracking.</p>
            @else
                <div class="space-y-2">
                    @foreach($metric as $task)
                        <div class="bg-white border border-gray-200 rounded-md px-4 py-3 flex justify-between items-center">
                            <div>
                                <p class="font-medium text-gray-900">{{ $task->appliance->name }} — {{ $task->name }}</p>
                                <p class="text-sm text-gray-500">Every {{ $task->interval_value }} {{ $task->interval_unit }}</p>
                            </div>
                            <span class="text-xs text-gray-400 border border-gray-200 rounded px-2 py-1">No date</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

    </div>
</div>
