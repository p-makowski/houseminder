<?php

declare(strict_types=1);

use App\Actions\RecordTaskCompletion;
use App\Models\Appliance;
use App\Models\MaintenanceTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Appliance $appliance;

    public string $sortBy = 'due_date';

    public ?int $deletingTaskId = null;

    public function mount(Appliance $appliance): void
    {
        $household = Auth::user()->households()->first();
        abort_if(! $household || $appliance->household_id !== $household->id, 403);

        $appliance->load(['applianceType']);
        $this->appliance = $appliance;
    }

    #[Computed]
    public function sortedTasks(): Collection
    {
        $tasks = $this->appliance->maintenanceTasks()->get();

        return match ($this->sortBy) {
            'name'     => $tasks->sortBy('name')->values(),
            'interval' => $tasks->sortBy(function ($task) {
                $multiplier = match ($task->interval_unit) {
                    'days'   => 1,
                    'weeks'  => 7,
                    'months' => 30,
                    'years'  => 365,
                    default  => PHP_INT_MAX,
                };

                return $task->interval_value * $multiplier;
            })->values(),
            default => $tasks->sortBy(fn ($t) => $t->next_due_at?->timestamp ?? PHP_INT_MAX)->values(),
        };
    }

    #[Computed]
    public function deletingTask(): ?MaintenanceTask
    {
        return $this->deletingTaskId !== null
            ? MaintenanceTask::find($this->deletingTaskId)
            : null;
    }

    public function confirmDelete(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        abort_if($task->appliance_id !== $this->appliance->id, 403);

        $this->deletingTaskId = $taskId;
    }

    public function deleteTask(): void
    {
        $task = MaintenanceTask::findOrFail($this->deletingTaskId);
        abort_if($task->appliance_id !== $this->appliance->id, 403);

        $task->delete();
        $this->deletingTaskId = null;
    }

    public function cancelDelete(): void
    {
        $this->deletingTaskId = null;
    }

    public function markDone(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        abort_if($task->appliance_id !== $this->appliance->id, 403);

        (new RecordTaskCompletion)($task, Auth::user());
    }

    public function setSortBy(string $key): void
    {
        if (! in_array($key, ['name', 'due_date', 'interval'], strict: true)) {
            return;
        }

        $this->sortBy = $key;
    }
}; ?>

<div class="max-w-2xl mx-auto py-8 px-4">
    <div class="mb-6">
        <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-indigo-600 hover:text-indigo-800">
            &larr; Dashboard
        </a>
    </div>

    <div class="bg-white border border-gray-200 rounded-md p-6 mb-6">
        <h1 class="text-2xl font-bold text-gray-900 mb-1">{{ $appliance->name }}</h1>
        <p class="text-gray-500 text-sm mb-4">{{ $appliance->model }}</p>

        <div class="space-y-1 text-sm text-gray-700">
            <div><span class="font-medium">Type:</span> {{ $appliance->applianceType?->name ?? '—' }}</div>
            @if($appliance->purchase_date)
                <div><span class="font-medium">Purchased:</span> {{ $appliance->purchase_date->format('M j, Y') }}</div>
            @endif
        </div>
    </div>

    <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-semibold text-gray-800">Maintenance Plan</h2>

        <div class="inline-flex rounded-md shadow-sm" role="group">
            <button wire:click="setSortBy('name')"
                class="px-3 py-1 text-sm rounded-l-md border {{ $sortBy === 'name' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
                Name
            </button>
            <button wire:click="setSortBy('due_date')"
                class="px-3 py-1 text-sm border-t border-b {{ $sortBy === 'due_date' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
                Due date
            </button>
            <button wire:click="setSortBy('interval')"
                class="px-3 py-1 text-sm rounded-r-md border {{ $sortBy === 'interval' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
                Frequency
            </button>
        </div>
    </div>

    @if($this->sortedTasks->isEmpty())
        <p class="text-gray-500">No maintenance tasks.</p>
    @else
        <div class="space-y-3">
            @foreach($this->sortedTasks as $task)
                @php
                    $status = match(true) {
                        $task->next_due_at !== null && $task->next_due_at < now()               => 'overdue',
                        $task->next_due_at !== null && $task->next_due_at <= now()->addDays(7)  => 'due_soon',
                        $task->next_due_at !== null                                             => 'upcoming',
                        default                                                                 => 'metric',
                    };
                    $borderClass   = match($status) {
                        'overdue'  => 'border-red-200',
                        'due_soon' => 'border-yellow-200',
                        default    => 'border-gray-200',
                    };
                    $dateTextClass = match($status) {
                        'overdue'  => 'text-red-600',
                        'due_soon' => 'text-yellow-600',
                        default    => 'text-gray-500',
                    };
                @endphp

                <div class="bg-white border {{ $borderClass }} rounded-md p-4">
                    <div class="flex justify-between items-start">
                        <h3 class="font-medium text-gray-900">{{ $task->name }}</h3>
                        <div class="flex items-center gap-3">
                            @if($task->next_due_at)
                                <span class="text-xs {{ $dateTextClass }}">
                                    Due {{ $task->next_due_at->format('M j, Y') }}
                                </span>
                            @endif
                            <button wire:click="markDone({{ $task->id }})" wire:loading.attr="disabled"
                                class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded disabled:opacity-50">
                                Mark done
                            </button>
                            <button wire:click="confirmDelete({{ $task->id }})"
                                class="text-sm text-red-600 hover:text-red-800">
                                Delete
                            </button>
                        </div>
                    </div>

                    @if($task->description)
                        <p class="text-sm text-gray-500 mt-1">{{ $task->description }}</p>
                    @endif

                    <p class="text-xs text-gray-400 mt-2">
                        Every {{ $task->interval_value }} {{ $task->interval_unit }}
                        &mdash;
                        {{ $task->anchor_type === 'from_last_done' ? 'From last done' : 'Fixed calendar' }}
                    </p>
                </div>
            @endforeach
        </div>
    @endif

    @if($this->deletingTask)
        <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded-md p-6 max-w-sm w-full mx-4 shadow-xl">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Delete task?</h3>
                <p class="text-sm text-gray-600 mb-6">
                    This will permanently delete <strong>{{ $this->deletingTask->name }}</strong>
                    and all its service history records. This cannot be undone.
                </p>
                <div class="flex justify-end gap-3">
                    <button wire:click="cancelDelete"
                        class="text-sm text-gray-700 border border-gray-300 px-4 py-2 rounded hover:bg-gray-50">
                        Cancel
                    </button>
                    <button wire:click="deleteTask"
                        class="text-sm text-white bg-red-600 hover:bg-red-700 px-4 py-2 rounded">
                        Delete
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
