<?php

declare(strict_types=1);

use App\Models\Appliance;
use App\Models\ApplianceType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Appliance $appliance;

    public string $name = '';
    public string $model = '';
    public string $purchaseDate = '';
    public string $typeSearch = '';
    public ?int $selectedTypeId = null;
    public ?int $originalTypeId = null;
    public array $allTypes = [];
    public int $taskCount = 0;

    public function mount(Appliance $appliance): void
    {
        $household = Auth::user()->households()->first();
        abort_if(!$household, 403);
        abort_if($appliance->household_id !== $household->id, 403);

        $this->appliance = $appliance;
        $this->name = $appliance->name;
        $this->model = $appliance->model;
        $this->purchaseDate = $appliance->purchase_date?->format('Y-m-d') ?? '';
        $this->selectedTypeId = $appliance->appliance_type_id;
        $this->originalTypeId = $appliance->appliance_type_id;
        $this->typeSearch = $appliance->applianceType?->name ?? '';
        $this->taskCount = $appliance->maintenanceTasks()->count();

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

    public function save(): void
    {
        $this->validate([
            'name'         => ['required', 'string', 'max:255'],
            'model'        => ['required', 'string', 'max:255'],
            'purchaseDate' => ['nullable', 'date'],
            'typeSearch'   => ['required', 'string', 'max:255'],
        ]);

        $household = Auth::user()->households()->first();
        abort_if(!$household, 403);
        abort_if($this->appliance->household_id !== $household->id, 403);

        DB::transaction(function () use ($household) {
            if ($this->selectedTypeId) {
                $type = ApplianceType::findOrFail($this->selectedTypeId);
                abort_if($type->household_id !== null && $type->household_id !== $household->id, 403);
            } else {
                $type = ApplianceType::firstOrCreate([
                    'name'         => $this->typeSearch,
                    'household_id' => $household->id,
                ]);
            }

            $this->appliance->update([
                'name'              => $this->name,
                'model'             => $this->model,
                'purchase_date'     => $this->purchaseDate ?: null,
                'appliance_type_id' => $type->id,
            ]);
        });

        $this->redirect(route('appliances.show', $this->appliance), navigate: true);
    }

    public function delete(): void
    {
        $household = Auth::user()->households()->first();
        abort_if(!$household, 403);
        abort_if($this->appliance->household_id !== $household->id, 403);

        $this->appliance->delete();

        $this->redirect(route('appliances.index'), navigate: true);
    }
}; ?>

<x-slot name="header">
    <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Edit Appliance') }}
        </h2>
        <x-danger-button x-data @click="$dispatch('open-modal', 'confirm-appliance-delete')">
            {{ __('Delete Appliance') }}
        </x-danger-button>
    </div>
</x-slot>

<div>
<div class="py-12">
    <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
                <form wire:submit.prevent="save" class="space-y-6">

                    <div>
                        <x-input-label for="name" :value="__('Appliance Name')" />
                        <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" name="name" autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="model" :value="__('Model')" />
                        <x-text-input wire:model="model" id="model" class="block mt-1 w-full" type="text" name="model" />
                        <x-input-error :messages="$errors->get('model')" class="mt-2" />
                    </div>

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
                                <p class="mt-1 text-sm text-gray-500">
                                    New type "<span class="font-medium">{{ $typeSearch }}</span>" will be created.
                                </p>
                            @endif
                        </div>
                        @if($selectedTypeId !== null && $originalTypeId !== null && $selectedTypeId !== $originalTypeId)
                            <p class="mt-2 text-sm text-amber-600">
                                {{ __('Changing the appliance type will not affect existing maintenance tasks.') }}
                            </p>
                        @endif
                        <x-input-error :messages="$errors->get('typeSearch')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="purchaseDate" :value="__('Purchase Date (optional)')" />
                        <input
                            wire:model="purchaseDate"
                            id="purchaseDate"
                            type="date"
                            class="block mt-1 w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <a href="{{ route('appliances.show', $appliance) }}" wire:navigate
                           class="text-sm text-gray-600 hover:text-gray-900">
                            {{ __('Cancel') }}
                        </a>
                        <x-primary-button type="submit">
                            {{ __('Save Changes') }}
                        </x-primary-button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

<x-modal name="confirm-appliance-delete" :show="false" focusable>
    <form wire:submit.prevent="delete" class="p-6">
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Delete') }} {{ $appliance->name }}?
        </h2>
        <p class="mt-1 text-sm text-gray-600">
            {{ __('This will permanently delete') }}
            @if($taskCount > 0)
                {{ $taskCount }} {{ Str::plural('maintenance task', $taskCount) }} {{ __('and all service history.') }}
            @else
                {{ __('this appliance.') }}
            @endif
            {{ __('This action cannot be undone.') }}
        </p>
        <div class="mt-6 flex justify-end gap-3">
            <x-secondary-button type="button" x-on:click="$dispatch('close')">
                {{ __('Cancel') }}
            </x-secondary-button>
            <x-danger-button type="submit" wire:loading.attr="disabled">
                {{ __('Delete Appliance') }}
            </x-danger-button>
        </div>
    </form>
</x-modal>
</div>
