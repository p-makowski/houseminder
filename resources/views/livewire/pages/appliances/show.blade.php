<?php

declare(strict_types=1);

use App\Actions\RecordTaskCompletion;
use App\Models\Appliance;
use App\Models\MaintenanceTask;
use App\Support\CalendarInterval;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Appliance $appliance;

    public string $sortBy = 'due_date';

    public ?int $deletingTaskId = null;

    public ?int $editingTaskId = null;

    public string $editName = '';

    public string $editDescription = '';

    public int $editIntervalValue = 1;

    public string $editIntervalUnit = 'months';

    public string $editIntervalCategory = '';

    public ?string $editNextDueAt = null;

    public function mount(Appliance $appliance): void
    {
        $household = Auth::user()->households()->first();
        abort_if(! $household || $appliance->household_id !== $household->id, 403);

        $appliance->load(['applianceType']);
        $this->appliance = $appliance;
    }

    private function sortTasks(Collection $tasks): Collection
    {
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
    public function overdue(): Collection
    {
        return $this->sortTasks(
            $this->appliance->maintenanceTasks()
                ->whereIn('interval_unit', ['days', 'weeks', 'months', 'years'])
                ->whereNotNull('next_due_at')
                ->where('next_due_at', '<', now())
                ->get()
        );
    }

    #[Computed]
    public function dueThisWeek(): Collection
    {
        $now = now();

        return $this->sortTasks(
            $this->appliance->maintenanceTasks()
                ->whereIn('interval_unit', ['days', 'weeks', 'months', 'years'])
                ->whereNotNull('next_due_at')
                ->whereBetween('next_due_at', [$now, $now->copy()->addDays(7)])
                ->get()
        );
    }

    #[Computed]
    public function dueThisMonth(): Collection
    {
        $now = now();

        return $this->sortTasks(
            $this->appliance->maintenanceTasks()
                ->whereIn('interval_unit', ['days', 'weeks', 'months', 'years'])
                ->whereNotNull('next_due_at')
                ->where('next_due_at', '>', $now->copy()->addDays(7))
                ->where('next_due_at', '<=', $now->copy()->addDays(30))
                ->get()
        );
    }

    #[Computed]
    public function upcoming(): Collection
    {
        return $this->sortTasks(
            $this->appliance->maintenanceTasks()
                ->whereIn('interval_unit', ['days', 'weeks', 'months', 'years'])
                ->whereNotNull('next_due_at')
                ->where('next_due_at', '>', now()->addDays(30))
                ->get()
        );
    }

    #[Computed]
    public function metric(): Collection
    {
        return $this->sortTasks(
            $this->appliance->maintenanceTasks()
                ->whereIn('interval_unit', ['hours', 'km'])
                ->get()
        );
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
        if ($this->deletingTaskId === null) {
            return;
        }

        $task = MaintenanceTask::findOrFail($this->deletingTaskId);
        abort_if($task->appliance_id !== $this->appliance->id, 403);

        $task->delete();
        $this->deletingTaskId = null;
    }

    public function cancelDelete(): void
    {
        $this->deletingTaskId = null;
    }

    public function startEdit(int $taskId): void
    {
        $task = MaintenanceTask::findOrFail($taskId);
        abort_if($task->appliance_id !== $this->appliance->id, 403);

        $this->deletingTaskId = null;

        $this->editName            = $task->name;
        $this->editDescription     = $task->description ?? '';
        $this->editIntervalValue   = (int) $task->interval_value;
        $this->editIntervalUnit    = $task->interval_unit;
        $this->editIntervalCategory = in_array($task->interval_unit, ['days', 'weeks', 'months', 'years'], strict: true)
            ? 'calendar'
            : 'metric';
        $this->editNextDueAt       = null;
        $this->editingTaskId       = $taskId;
    }

    public function saveEdit(): void
    {
        if ($this->editingTaskId === null) {
            return;
        }

        if ($this->editNextDueAt === '') {
            $this->editNextDueAt = null;
        }

        $allowedUnits = $this->editIntervalCategory === 'calendar'
            ? ['days', 'weeks', 'months', 'years']
            : ['hours', 'km'];

        $validated = $this->validate([
            'editName'             => ['required', 'string', 'max:255'],
            'editDescription'      => ['nullable', 'string', 'max:1000'],
            'editIntervalValue'    => ['required', 'integer', 'min:1'],
            'editIntervalUnit'     => ['required', 'string', Rule::in($allowedUnits)],
            'editNextDueAt'        => ['nullable', 'date'],
        ]);

        $task = MaintenanceTask::findOrFail($this->editingTaskId);
        abort_if($task->appliance_id !== $this->appliance->id, 403);

        $task->fill([
            'name'           => $validated['editName'],
            'description'    => $validated['editDescription'] ?: null,
            'interval_value' => $validated['editIntervalValue'],
            'interval_unit'  => $validated['editIntervalUnit'],
        ]);

        if ($this->editIntervalCategory === 'calendar') {
            if (! empty($validated['editNextDueAt'])) {
                $task->next_due_at = Carbon::parse($validated['editNextDueAt']);
            } else {
                $anchor = $task->last_completed_at ?? $task->anchor_date ?? now();
                $task->next_due_at = CalendarInterval::calculateNextDueAt(
                    $anchor,
                    $task->interval_unit,
                    (int) $task->interval_value
                );
            }
        }

        DB::transaction(fn () => $task->save());

        $this->editingTaskId = null;
    }

    public function cancelEdit(): void
    {
        $this->editingTaskId = null;
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

    <div class="space-y-6">

        {{-- Overdue --}}
        <section>
            <h3 class="text-base font-semibold text-red-700 mb-2">Overdue</h3>
            @if($this->overdue->isEmpty())
                <p class="text-sm text-gray-500">No overdue tasks.</p>
            @else
                <div class="space-y-3">
                    @foreach($this->overdue as $task)
                        @if($editingTaskId === $task->id)
                            @include('livewire.pages.appliances._edit-form')
                        @else
                            <div class="bg-white border border-red-200 rounded-md p-4">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h3 class="font-medium text-gray-900">{{ $task->name }}</h3>
                                        @if(!$task->is_confirmed)
                                            <span class="text-xs text-amber-600 border border-amber-200 bg-amber-50 rounded px-1.5 py-0.5 mt-0.5 inline-block">Draft</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs text-red-600">Due {{ $task->next_due_at->format('M j, Y') }}</span>
                                        <button wire:click="markDone({{ $task->id }})" wire:loading.attr="disabled"
                                            class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded disabled:opacity-50">
                                            Mark done
                                        </button>
                                        <button wire:click="startEdit({{ $task->id }})" class="text-sm text-indigo-600 hover:text-indigo-800">Edit</button>
                                        <button wire:click="confirmDelete({{ $task->id }})" class="text-sm text-red-600 hover:text-red-800">Delete</button>
                                    </div>
                                </div>
                                @if($task->description)
                                    <p class="text-sm text-gray-500 mt-1">{{ $task->description }}</p>
                                @endif
                                <p class="text-xs text-gray-400 mt-2">Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Due this week --}}
        <section>
            <h3 class="text-base font-semibold text-yellow-700 mb-2">Due this week</h3>
            @if($this->dueThisWeek->isEmpty())
                <p class="text-sm text-gray-500">Nothing due this week.</p>
            @else
                <div class="space-y-3">
                    @foreach($this->dueThisWeek as $task)
                        @if($editingTaskId === $task->id)
                            @include('livewire.pages.appliances._edit-form')
                        @else
                            <div class="bg-white border border-yellow-200 rounded-md p-4">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h3 class="font-medium text-gray-900">{{ $task->name }}</h3>
                                        @if(!$task->is_confirmed)
                                            <span class="text-xs text-amber-600 border border-amber-200 bg-amber-50 rounded px-1.5 py-0.5 mt-0.5 inline-block">Draft</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs text-yellow-600">Due {{ $task->next_due_at->format('M j, Y') }}</span>
                                        <button wire:click="markDone({{ $task->id }})" wire:loading.attr="disabled"
                                            class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded disabled:opacity-50">
                                            Mark done
                                        </button>
                                        <button wire:click="startEdit({{ $task->id }})" class="text-sm text-indigo-600 hover:text-indigo-800">Edit</button>
                                        <button wire:click="confirmDelete({{ $task->id }})" class="text-sm text-red-600 hover:text-red-800">Delete</button>
                                    </div>
                                </div>
                                @if($task->description)
                                    <p class="text-sm text-gray-500 mt-1">{{ $task->description }}</p>
                                @endif
                                <p class="text-xs text-gray-400 mt-2">Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>

        {{-- This month --}}
        <section>
            <h3 class="text-base font-semibold text-blue-700 mb-2">This month</h3>
            @if($this->dueThisMonth->isEmpty())
                <p class="text-sm text-gray-500">Nothing due this month.</p>
            @else
                <div class="space-y-3">
                    @foreach($this->dueThisMonth as $task)
                        @if($editingTaskId === $task->id)
                            @include('livewire.pages.appliances._edit-form')
                        @else
                            <div class="bg-white border border-blue-200 rounded-md p-4">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h3 class="font-medium text-gray-900">{{ $task->name }}</h3>
                                        @if(!$task->is_confirmed)
                                            <span class="text-xs text-amber-600 border border-amber-200 bg-amber-50 rounded px-1.5 py-0.5 mt-0.5 inline-block">Draft</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs text-blue-600">Due {{ $task->next_due_at->format('M j, Y') }}</span>
                                        <button wire:click="markDone({{ $task->id }})" wire:loading.attr="disabled"
                                            class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded disabled:opacity-50">
                                            Mark done
                                        </button>
                                        <button wire:click="startEdit({{ $task->id }})" class="text-sm text-indigo-600 hover:text-indigo-800">Edit</button>
                                        <button wire:click="confirmDelete({{ $task->id }})" class="text-sm text-red-600 hover:text-red-800">Delete</button>
                                    </div>
                                </div>
                                @if($task->description)
                                    <p class="text-sm text-gray-500 mt-1">{{ $task->description }}</p>
                                @endif
                                <p class="text-xs text-gray-400 mt-2">Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Upcoming --}}
        <section>
            <h3 class="text-base font-semibold text-gray-700 mb-2">Upcoming</h3>
            @if($this->upcoming->isEmpty())
                <p class="text-sm text-gray-500">No upcoming tasks.</p>
            @else
                <div class="space-y-3">
                    @foreach($this->upcoming as $task)
                        @if($editingTaskId === $task->id)
                            @include('livewire.pages.appliances._edit-form')
                        @else
                            <div class="bg-white border border-gray-200 rounded-md p-4">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h3 class="font-medium text-gray-900">{{ $task->name }}</h3>
                                        @if(!$task->is_confirmed)
                                            <span class="text-xs text-amber-600 border border-amber-200 bg-amber-50 rounded px-1.5 py-0.5 mt-0.5 inline-block">Draft</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs text-gray-500">Due {{ $task->next_due_at->format('M j, Y') }}</span>
                                        <button wire:click="markDone({{ $task->id }})" wire:loading.attr="disabled"
                                            class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1 rounded disabled:opacity-50">
                                            Mark done
                                        </button>
                                        <button wire:click="startEdit({{ $task->id }})" class="text-sm text-indigo-600 hover:text-indigo-800">Edit</button>
                                        <button wire:click="confirmDelete({{ $task->id }})" class="text-sm text-red-600 hover:text-red-800">Delete</button>
                                    </div>
                                </div>
                                @if($task->description)
                                    <p class="text-sm text-gray-500 mt-1">{{ $task->description }}</p>
                                @endif
                                <p class="text-xs text-gray-400 mt-2">Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Manual tracking --}}
        <section>
            <h3 class="text-base font-semibold text-gray-700 mb-2">Manual tracking</h3>
            @if($this->metric->isEmpty())
                <p class="text-sm text-gray-500">No tasks requiring manual tracking.</p>
            @else
                <div class="space-y-3">
                    @foreach($this->metric as $task)
                        @if($editingTaskId === $task->id)
                            @include('livewire.pages.appliances._edit-form')
                        @else
                            <div class="bg-white border border-gray-200 rounded-md p-4">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h3 class="font-medium text-gray-900">{{ $task->name }}</h3>
                                        @if(!$task->is_confirmed)
                                            <span class="text-xs text-amber-600 border border-amber-200 bg-amber-50 rounded px-1.5 py-0.5 mt-0.5 inline-block">Draft</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <button wire:click="startEdit({{ $task->id }})" class="text-sm text-indigo-600 hover:text-indigo-800">Edit</button>
                                        <button wire:click="confirmDelete({{ $task->id }})" class="text-sm text-red-600 hover:text-red-800">Delete</button>
                                    </div>
                                </div>
                                @if($task->description)
                                    <p class="text-sm text-gray-500 mt-1">{{ $task->description }}</p>
                                @endif
                                <p class="text-xs text-gray-400 mt-2">Every {{ $task->interval_value }} {{ $task->interval_unit }}</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>

    </div>

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
