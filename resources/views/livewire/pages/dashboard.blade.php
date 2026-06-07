<?php

declare(strict_types=1);

use App\Actions\RecordTaskCompletion;
use App\Models\MaintenanceTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        abort_if(! Auth::user()->households()->first(), 403);
    }

    #[Computed]
    public function householdId(): int
    {
        return Auth::user()->households()->first()->id;
    }

    public function markDone(int $taskId): void
    {
        $task = MaintenanceTask::calendar()->forHousehold($this->householdId)->findOrFail($taskId);

        (new RecordTaskCompletion)($task, Auth::user());
    }

    #[Computed]
    public function overdue(): Collection
    {
        return MaintenanceTask::calendar()
            ->forHousehold($this->householdId)
            ->where('next_due_at', '<', now())
            ->orderBy('next_due_at')
            ->with('appliance')
            ->get();
    }

    #[Computed]
    public function dueThisWeek(): Collection
    {
        $now = now();

        return MaintenanceTask::calendar()
            ->forHousehold($this->householdId)
            ->whereBetween('next_due_at', [$now, $now->copy()->addDays(7)])
            ->orderBy('next_due_at')
            ->with('appliance')
            ->get();
    }

    #[Computed]
    public function dueThisMonth(): Collection
    {
        $now = now();

        return MaintenanceTask::calendar()
            ->forHousehold($this->householdId)
            ->where('next_due_at', '>', $now->copy()->addDays(7))
            ->where('next_due_at', '<=', $now->copy()->addDays(30))
            ->orderBy('next_due_at')
            ->with('appliance')
            ->get();
    }

    #[Computed]
    public function upcoming(): Collection
    {
        return MaintenanceTask::calendar()
            ->forHousehold($this->householdId)
            ->where('next_due_at', '>', now()->addDays(30))
            ->orderBy('next_due_at')
            ->with('appliance')
            ->get();
    }

    #[Computed]
    public function metric(): Collection
    {
        return MaintenanceTask::metric()
            ->forHousehold($this->householdId)
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
            @if($this->overdue->isEmpty())
                <p class="text-sm text-gray-500">No overdue tasks.</p>
            @else
                <div class="space-y-2">
                    @foreach($this->overdue as $task)
                        <div class="bg-white border border-red-200 rounded-md px-4 py-3 flex justify-between items-center">
                            <div>
                                <p class="font-medium text-gray-900">{{ $task->appliance->name }} — {{ $task->name }}</p>
                                <p class="text-sm text-red-600">Due {{ $task->next_due_at->format('M j, Y') }}</p>
                                <p class="text-sm text-gray-500">Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}</p>
                            </div>
                            <button wire:click="markDone({{ $task->id }})" wire:loading.attr="disabled" class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded disabled:opacity-50">
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
            @if($this->dueThisWeek->isEmpty())
                <p class="text-sm text-gray-500">Nothing due this week.</p>
            @else
                <div class="space-y-2">
                    @foreach($this->dueThisWeek as $task)
                        <div class="bg-white border border-yellow-200 rounded-md px-4 py-3 flex justify-between items-center">
                            <div>
                                <p class="font-medium text-gray-900">{{ $task->appliance->name }} — {{ $task->name }}</p>
                                <p class="text-sm text-yellow-600">Due {{ $task->next_due_at->format('M j, Y') }}</p>
                                <p class="text-sm text-gray-500">Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}</p>
                            </div>
                            <button wire:click="markDone({{ $task->id }})" wire:loading.attr="disabled" class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded disabled:opacity-50">
                                Mark done
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- This month --}}
        <section>
            <h2 class="text-lg font-semibold text-blue-700 mb-3">This month</h2>
            @if($this->dueThisMonth->isEmpty())
                <p class="text-sm text-gray-500">Nothing due this month.</p>
            @else
                <div class="space-y-2">
                    @foreach($this->dueThisMonth as $task)
                        <div class="bg-white border border-blue-200 rounded-md px-4 py-3 flex justify-between items-center">
                            <div>
                                <p class="font-medium text-gray-900">{{ $task->appliance->name }} — {{ $task->name }}</p>
                                <p class="text-sm text-blue-600">Due {{ $task->next_due_at->format('M j, Y') }}</p>
                                <p class="text-sm text-gray-500">Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}</p>
                            </div>
                            <button wire:click="markDone({{ $task->id }})" wire:loading.attr="disabled" class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded disabled:opacity-50">
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
            @if($this->upcoming->isEmpty())
                <p class="text-sm text-gray-500">No upcoming tasks.</p>
            @else
                <div class="space-y-2">
                    @foreach($this->upcoming as $task)
                        <div class="bg-white border border-gray-200 rounded-md px-4 py-3 flex justify-between items-center">
                            <div>
                                <p class="font-medium text-gray-900">{{ $task->appliance->name }} — {{ $task->name }}</p>
                                <p class="text-sm text-gray-500">Due {{ $task->next_due_at->format('M j, Y') }}</p>
                                <p class="text-sm text-gray-500">Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}</p>
                            </div>
                            <button wire:click="markDone({{ $task->id }})" wire:loading.attr="disabled" class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded disabled:opacity-50">
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
            @if($this->metric->isEmpty())
                <p class="text-sm text-gray-500">No tasks requiring manual tracking.</p>
            @else
                <div class="space-y-2">
                    @foreach($this->metric as $task)
                        <div class="bg-white border border-gray-200 rounded-md px-4 py-3 flex justify-between items-center">
                            <div>
                                <p class="font-medium text-gray-900">{{ $task->appliance->name }} — {{ $task->name }}</p>
                                <p class="text-sm text-gray-500">Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}</p>
                            </div>
                            <span class="text-xs text-gray-400 border border-gray-200 rounded px-2 py-1">No date</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

    </div>
</div>
