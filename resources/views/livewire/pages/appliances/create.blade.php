<?php

declare(strict_types=1);

use App\Actions\GenerateMaintenancePlan;
use App\Models\Appliance;
use App\Models\ApplianceType;
use App\Models\ServiceRecord;
use App\Support\CalendarInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Prism\Prism\Exceptions\PrismException;

new #[Layout('layouts.app')] class extends Component
{
    public int $step = 1;

    public string $name = '';
    public string $model = '';
    public string $typeSearch = '';
    public ?int $selectedTypeId = null;
    public string $purchaseDate = '';
    public array $allTypes = [];

    public bool $aiLoading = false;
    public ?string $aiError = null;
    public array $tasks = [];
    public array $backdates = [];

    public function mount(): void
    {
        $household = Auth::user()->households()->first();
        abort_if(!$household, 403);

        $householdId = $household->id;

        $this->allTypes = ApplianceType::whereNull('household_id')
            ->orWhere('household_id', $householdId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($t) => ['id' => $t->id, 'name' => $t->name])
            ->toArray();
    }

    public function selectType(int $id, string $name): void
    {
        $this->selectedTypeId = $id;
        $this->typeSearch = $name;
    }

    public function nextStep(): void
    {
        match ($this->step) {
            1 => $this->advanceFromStep1(),
            2 => $this->advanceFromStep2(),
            default => $this->step++,
        };
    }

    public function prevStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
        $this->aiError = null;
    }

    public function advanceFromStep1(): void
    {
        $this->validate([
            'name'       => ['required', 'string', 'max:255'],
            'model'      => ['required', 'string', 'max:255'],
            'typeSearch' => ['required', 'string', 'max:255'],
        ]);

        $this->aiLoading = true;
        $this->step = 2;
    }

    public function advanceFromStep2(): void
    {
        if (empty($this->tasks)) {
            $this->aiError = 'At least one task is required before continuing.';
            return;
        }

        $this->backdates = array_fill(0, count($this->tasks), [
            'date'   => '',
            'metric' => '',
            'notes'  => '',
            'skip'   => false,
        ]);

        $this->step = 3;
    }

    public function fetchSuggestions(): void
    {
        try {
            $result = app(GenerateMaintenancePlan::class)(
                $this->name,
                $this->model,
                $this->typeSearch,
            );

            $this->tasks = array_map(fn($task) => array_merge($task, [
                'anchor_type' => 'from_last_done',
            ]), $result);

            $this->aiError = null;
        } catch (PrismException $e) {
            $this->aiError = 'Could not generate maintenance tasks. Please try again.';
            Log::error('GenerateMaintenancePlan failed', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->aiError = 'An unexpected error occurred. Please try again.';
            Log::error('GenerateMaintenancePlan unexpected error', ['error' => $e->getMessage()]);
        } finally {
            $this->aiLoading = false;
        }
    }

    public function retryFetch(): void
    {
        $this->aiError = null;
        $this->aiLoading = true;
        $this->fetchSuggestions();
    }

    public function deleteTask(int $index): void
    {
        array_splice($this->tasks, $index, 1);
        $this->tasks = array_values($this->tasks);
    }

    public function addTask(): void
    {
        if (count($this->tasks) >= 20) {
            return;
        }

        $this->tasks[] = [
            'name'           => '',
            'description'    => '',
            'interval_value' => 6,
            'interval_unit'  => 'months',
            'anchor_type'    => 'from_last_done',
        ];
    }

    public function confirm(): void
    {
        abort_if(count($this->backdates) !== count($this->tasks), 422);

        $this->validate([
            'tasks.*.name'           => ['required', 'string', 'max:255'],
            'tasks.*.interval_value' => ['required', 'integer', 'min:1'],
            'tasks.*.interval_unit'  => ['required', 'in:days,weeks,months,years'],
            'tasks.*.anchor_type'    => ['required', 'in:from_last_done,fixed_calendar'],
            'tasks.*.description'    => ['nullable', 'string', 'max:1000'],
            'backdates.*.date'       => ['nullable', 'date'],
            'backdates.*.metric'     => ['nullable', 'numeric'],
            'backdates.*.notes'      => ['nullable', 'string', 'max:2000'],
            'backdates.*.skip'       => ['boolean'],
        ]);

        $household = Auth::user()->households()->first();
        abort_if(!$household, 403);

        $appliance = DB::transaction(function () use ($household) {
            if ($this->selectedTypeId) {
                $type = ApplianceType::findOrFail($this->selectedTypeId);
                abort_if($type->household_id !== null && $type->household_id !== $household->id, 403);
            } else {
                $type = ApplianceType::firstOrCreate([
                    'name'         => $this->typeSearch,
                    'household_id' => $household->id,
                ]);
            }

            $appliance = Appliance::create([
                'household_id'      => $household->id,
                'appliance_type_id' => $type->id,
                'name'              => $this->name,
                'model'             => $this->model,
                'purchase_date'     => $this->purchaseDate ?: null,
                'is_plan_confirmed' => true,
            ]);

            foreach ($this->tasks as $i => $task) {
                $backdate = $this->backdates[$i] ?? [];
                $skipped  = $backdate['skip'] ?? false;
                $hasDate  = !$skipped && !empty($backdate['date']);

                $anchorDate = $hasDate
                    ? Carbon::parse($backdate['date'])
                    : Carbon::today();

                $isCalendar = in_array($task['interval_unit'], ['days', 'weeks', 'months', 'years'], true);
                $nextDueAt  = $isCalendar
                    ? CalendarInterval::calculateNextDueAt($anchorDate, $task['interval_unit'], (int) $task['interval_value'])
                    : null;

                $isFromLastDone  = $task['anchor_type'] === 'from_last_done';
                $isFixedCalendar = $task['anchor_type'] === 'fixed_calendar';

                $maintenanceTask = $appliance->maintenanceTasks()->create([
                    'name'              => $task['name'],
                    'description'       => $task['description'] ?? null,
                    'interval_value'    => (int) $task['interval_value'],
                    'interval_unit'     => $task['interval_unit'],
                    'anchor_type'       => $task['anchor_type'],
                    'anchor_date'       => $isFixedCalendar ? $anchorDate : null,
                    'last_completed_at' => ($isFromLastDone && $hasDate) ? $anchorDate : null,
                    'next_due_at'       => $nextDueAt,
                    'next_due_at_value' => null,
                    'is_confirmed'      => true,
                ]);

                $hasMetric = !$skipped && !empty($backdate['metric']);

                if ($isFromLastDone && ($hasDate || $hasMetric)) {
                    ServiceRecord::create([
                        'maintenance_task_id' => $maintenanceTask->id,
                        'completed_at'        => $hasDate ? $anchorDate : Carbon::today(),
                        'metric_reading'      => $hasMetric ? $backdate['metric'] : null,
                        'notes'               => !empty($backdate['notes']) ? $backdate['notes'] : null,
                    ]);
                }
            }

            return $appliance;
        });

        $this->redirect(route('appliances.show', $appliance), navigate: true);
    }
}; ?>

<div>
    {{-- Step 1: Appliance Details --}}
    @if($step === 1)
        <div class="max-w-2xl mx-auto py-8 px-4">
            <h1 class="text-2xl font-bold text-gray-900 mb-6">Add Appliance</h1>

            <form wire:submit.prevent="nextStep" class="space-y-6">
                {{-- Name --}}
                <div>
                    <x-input-label for="name" :value="__('Appliance Name')" />
                    <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" name="name" autofocus />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                {{-- Model --}}
                <div>
                    <x-input-label for="model" :value="__('Model')" />
                    <x-text-input wire:model="model" id="model" class="block mt-1 w-full" type="text" name="model" />
                    <x-input-error :messages="$errors->get('model')" class="mt-2" />
                </div>

                {{-- Type combobox --}}
                <div>
                    <x-input-label :value="__('Appliance Type')" />
                    <div x-data="{
                        search: @entangle('typeSearch'),
                        open: false,
                        types: @js($allTypes),
                        get filtered() {
                            if (!this.search) return this.types;
                            return this.types.filter(t => t.name.toLowerCase().includes(this.search.toLowerCase()));
                        }
                    }" class="relative mt-1">
                        <x-text-input
                            x-model="search"
                            @focus="open = true"
                            @click.outside="open = false"
                            @input="$wire.set('selectedTypeId', null)"
                            class="block w-full"
                            type="text"
                            placeholder="Search or enter a type…"
                        />
                        <ul x-show="open && filtered.length > 0"
                            class="absolute z-10 mt-1 w-full bg-white border border-gray-300 rounded-md shadow-lg max-h-60 overflow-auto">
                            <template x-for="type in filtered" :key="type.id">
                                <li
                                    @click="$wire.selectType(type.id, type.name); open = false"
                                    class="cursor-pointer px-4 py-2 hover:bg-indigo-50 text-sm"
                                    x-text="type.name"
                                ></li>
                            </template>
                        </ul>
                        @if(!$selectedTypeId && $typeSearch)
                            <p class="mt-1 text-sm text-gray-500" wire:key="new-type-hint">
                                New type "<span class="font-medium">{{ $typeSearch }}</span>" will be created.
                            </p>
                        @endif
                    </div>
                    <x-input-error :messages="$errors->get('typeSearch')" class="mt-2" />
                </div>

                {{-- Purchase Date --}}
                <div>
                    <x-input-label for="purchaseDate" :value="__('Purchase Date (optional)')" />
                    <input
                        wire:model="purchaseDate"
                        id="purchaseDate"
                        type="date"
                        class="block mt-1 w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>

                <div class="flex justify-end">
                    <x-primary-button type="submit">
                        {{ __('Next') }}
                    </x-primary-button>
                </div>
            </form>
        </div>
    @endif

    {{-- Step 2: AI Suggestions + Review --}}
    @if($step === 2)
        <div class="max-w-2xl mx-auto py-8 px-4">
            <h1 class="text-2xl font-bold text-gray-900 mb-6">Maintenance Tasks</h1>

            @if($aiLoading)
                <div x-data x-init="$wire.fetchSuggestions()" class="flex flex-col items-center justify-center py-16">
                    <svg class="animate-spin h-10 w-10 text-indigo-500 mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    <p class="text-gray-500">Generating maintenance plan…</p>
                </div>
            @elseif($aiError && !$aiLoading)
                <div class="bg-red-50 border border-red-200 rounded-md p-4 mb-6">
                    <p class="text-red-700">{{ $aiError }}</p>
                </div>
                <div class="flex gap-3">
                    <button
                        wire:click="retryFetch"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 disabled:opacity-50"
                    >
                        Retry
                    </button>
                    <button wire:click="prevStep" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                        Back
                    </button>
                </div>
            @elseif(!$aiLoading && !$aiError && count($tasks) > 0)
                <form wire:submit.prevent="nextStep" class="space-y-4">
                    @foreach($tasks as $i => $task)
                        <div class="border border-gray-200 rounded-md p-4 space-y-3" wire:key="task-{{ $i }}">
                            <div class="flex justify-between items-start">
                                <div class="flex-1 mr-4">
                                    <x-input-label :value="__('Task Name')" />
                                    <x-text-input
                                        wire:model="tasks.{{ $i }}.name"
                                        class="block mt-1 w-full"
                                        type="text"
                                    />
                                </div>
                                <button
                                    wire:click.prevent="deleteTask({{ $i }})"
                                    class="mt-6 text-sm text-red-500 hover:text-red-700"
                                >
                                    Remove
                                </button>
                            </div>

                            @if(!empty($task['description']))
                                <p class="text-sm text-gray-500 italic">{{ $task['description'] }}</p>
                            @endif

                            <div class="flex gap-3">
                                <div class="w-24">
                                    <x-input-label :value="__('Every')" />
                                    <input
                                        wire:model="tasks.{{ $i }}.interval_value"
                                        type="number"
                                        min="1"
                                        class="block mt-1 w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    />
                                </div>
                                <div class="flex-1">
                                    <x-input-label :value="__('Unit')" />
                                    <select
                                        wire:model="tasks.{{ $i }}.interval_unit"
                                        class="block mt-1 w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    >
                                        <option value="days">Days</option>
                                        <option value="weeks">Weeks</option>
                                        <option value="months">Months</option>
                                        <option value="years">Years</option>
                                    </select>
                                </div>
                                <div class="flex-1">
                                    <x-input-label :value="__('Schedule type')" />
                                    <select
                                        wire:model="tasks.{{ $i }}.anchor_type"
                                        class="block mt-1 w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    >
                                        <option value="from_last_done">From last done</option>
                                        <option value="fixed_calendar">Fixed calendar</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <button
                        wire:click.prevent="addTask"
                        class="inline-flex items-center text-sm text-indigo-600 hover:text-indigo-800"
                    >
                        + Add task
                    </button>

                    <div class="flex justify-between pt-4">
                        <button type="button" wire:click="prevStep" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                            Back
                        </button>
                        <x-primary-button type="submit">
                            {{ __('Next') }}
                        </x-primary-button>
                    </div>
                </form>
            @endif
        </div>
    @endif

    {{-- Step 3: Backdate --}}
    @if($step === 3)
        <div class="max-w-2xl mx-auto py-8 px-4">
            <h1 class="text-2xl font-bold text-gray-900 mb-2">When did you last service these?</h1>
            <p class="text-gray-500 mb-6">Optionally backdate tasks to calculate more accurate due dates.</p>

            <div class="space-y-4">
                @foreach($tasks as $i => $task)
                    <div class="border border-gray-200 rounded-md p-4" wire:key="backdate-{{ $i }}">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-medium text-gray-900">{{ $task['name'] }}</h3>
                            <label class="flex items-center gap-2 text-sm text-gray-600">
                                <input
                                    wire:model.live="backdates.{{ $i }}.skip"
                                    type="checkbox"
                                    class="rounded border-gray-300"
                                />
                                Skip
                            </label>
                        </div>

                        @if(!($backdates[$i]['skip'] ?? false))
                            <div class="space-y-3">
                                <div>
                                    <x-input-label :value="$task['anchor_type'] === 'from_last_done' ? __('When did you last do this?') : __('Schedule from this date:')" />
                                    <input
                                        wire:model="backdates.{{ $i }}.date"
                                        type="date"
                                        class="block mt-1 w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>

                                @if(in_array($task['interval_unit'] ?? '', ['hours', 'km']))
                                    <div>
                                        <x-input-label :value="__('Metric reading')" />
                                        <x-text-input
                                            wire:model="backdates.{{ $i }}.metric"
                                            class="block mt-1 w-full"
                                            type="text"
                                        />
                                    </div>
                                @endif

                                <div>
                                    <x-input-label :value="__('Notes (optional)')" />
                                    <textarea
                                        wire:model="backdates.{{ $i }}.notes"
                                        rows="2"
                                        class="block mt-1 w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    ></textarea>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="flex justify-between pt-6">
                <button wire:click="prevStep" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Back
                </button>
                <x-primary-button wire:click="nextStep">
                    {{ __('Next') }}
                </x-primary-button>
            </div>
        </div>
    @endif

    {{-- Step 4: Confirmation Summary --}}
    @if($step === 4)
        <div class="max-w-2xl mx-auto py-8 px-4">
            <h1 class="text-2xl font-bold text-gray-900 mb-6">Confirm Your Plan</h1>

            <div class="bg-gray-50 rounded-md p-4 mb-6 space-y-2">
                <div><span class="font-medium">Name:</span> {{ $name }}</div>
                <div><span class="font-medium">Model:</span> {{ $model }}</div>
                <div><span class="font-medium">Type:</span> {{ $typeSearch }}</div>
                <div><span class="font-medium">Tasks:</span> {{ count($tasks) }}</div>
            </div>

            <h2 class="text-lg font-semibold text-gray-800 mb-3">Maintenance Tasks</h2>
            <div class="space-y-3 mb-8">
                @foreach($tasks as $task)
                    <div class="border border-gray-200 rounded-md p-3">
                        <p class="font-medium">{{ $task['name'] }}</p>
                        <p class="text-sm text-gray-500">
                            Every {{ $task['interval_value'] }} {{ $task['interval_unit'] }}
                            &mdash;
                            {{ $task['anchor_type'] === 'from_last_done' ? 'From last done' : 'Fixed calendar' }}
                        </p>
                    </div>
                @endforeach
            </div>

            <div class="flex justify-between">
                <button wire:click="prevStep" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Back
                </button>
                <x-primary-button wire:click="confirm" wire:loading.attr="disabled">
                    {{ __('Confirm Plan') }}
                </x-primary-button>
            </div>
        </div>
    @endif
</div>
