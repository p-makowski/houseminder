<div class="bg-white border border-indigo-300 rounded-md p-4 space-y-3">

    {{-- Section 1: Task definition --}}
    <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Name</label>
        <input wire:model="addName" type="text"
            class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:ring-indigo-500 focus:border-indigo-500">
        @error('addName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
        <textarea wire:model="addDescription" rows="2"
            class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:ring-indigo-500 focus:border-indigo-500"></textarea>
    </div>

    <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Category</label>
        <div class="flex gap-4 text-sm">
            <label class="inline-flex items-center gap-1.5">
                <input wire:model.live="addIntervalCategory" type="radio" value="calendar" class="text-indigo-600">
                Calendar
            </label>
            <label class="inline-flex items-center gap-1.5">
                <input wire:model.live="addIntervalCategory" type="radio" value="metric" class="text-indigo-600">
                Metric
            </label>
        </div>
    </div>

    <div class="flex gap-2">
        <div class="flex-1">
            <label class="block text-xs font-medium text-gray-700 mb-1">Every</label>
            <input wire:model.number="addIntervalValue" type="number" min="1"
                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm">
            @error('addIntervalValue') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="flex-1">
            <label class="block text-xs font-medium text-gray-700 mb-1">Unit</label>
            <select wire:model="addIntervalUnit"
                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm">
                @if($addIntervalCategory === 'calendar')
                    <option value="days">days</option>
                    <option value="weeks">weeks</option>
                    <option value="months">months</option>
                    <option value="years">years</option>
                @else
                    <option value="hours">hours</option>
                    <option value="km">km</option>
                @endif
            </select>
            @error('addIntervalUnit') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    @if($addIntervalCategory === 'calendar')
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">
                Next due date
                <span class="font-normal text-gray-400">(leave blank to auto-calculate)</span>
            </label>
            <input wire:model="addNextDueAt" type="date"
                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm">
            @error('addNextDueAt') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
    @endif

    {{-- Section 2: Last service (optional) --}}
    <p class="text-xs font-medium text-gray-500 pt-1">Last service (optional)</p>

    <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">When did you last do this?</label>
        <input wire:model="addLastDoneAt" type="date"
            class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm">
        @error('addLastDoneAt') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    @if($addIntervalCategory === 'metric')
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Metric reading</label>
            <input wire:model="addLastMetric" type="number" step="any"
                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm">
            @error('addLastMetric') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
    @endif

    <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Notes (optional)</label>
        <textarea wire:model="addNotes" rows="2"
            class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:ring-indigo-500 focus:border-indigo-500"></textarea>
        @error('addNotes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="flex items-center gap-3 pt-1">
        <button wire:click="saveNewTask" wire:loading.attr="disabled"
            class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-4 py-1.5 rounded disabled:opacity-50">
            Save
        </button>
        <button wire:click="cancelAddTask" class="text-sm text-gray-600 hover:text-gray-800">
            Cancel
        </button>
    </div>
</div>
