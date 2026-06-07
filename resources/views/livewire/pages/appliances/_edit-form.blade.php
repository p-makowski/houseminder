<div class="bg-white border border-indigo-300 rounded-md p-4 space-y-3">
    <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Name</label>
        <input wire:model="editName" type="text"
            class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:ring-indigo-500 focus:border-indigo-500">
        @error('editName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
        <textarea wire:model="editDescription" rows="2"
            class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm focus:ring-indigo-500 focus:border-indigo-500"></textarea>
    </div>

    <div class="flex gap-2">
        <div class="flex-1">
            <label class="block text-xs font-medium text-gray-700 mb-1">Every</label>
            <input wire:model.number="editIntervalValue" type="number" min="1"
                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm">
            @error('editIntervalValue') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="flex-1">
            <label class="block text-xs font-medium text-gray-700 mb-1">Unit</label>
            <select wire:model="editIntervalUnit"
                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm">
                @if($editIntervalCategory === 'calendar')
                    <option value="days">days</option>
                    <option value="weeks">weeks</option>
                    <option value="months">months</option>
                    <option value="years">years</option>
                @else
                    <option value="hours">hours</option>
                    <option value="km">km</option>
                @endif
            </select>
        </div>
    </div>

    @if($editIntervalCategory === 'calendar')
        <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">
                Next due date
                <span class="font-normal text-gray-400">(leave blank to auto-calculate)</span>
            </label>
            <input wire:model="editNextDueAt" type="date"
                class="w-full border border-gray-300 rounded px-3 py-1.5 text-sm">
            @error('editNextDueAt') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
    @endif

    <div class="flex items-center gap-3 pt-1">
        <button wire:click="saveEdit"
            class="text-sm text-white bg-indigo-600 hover:bg-indigo-700 px-4 py-1.5 rounded">
            Save
        </button>
        <button wire:click="cancelEdit" class="text-sm text-gray-600 hover:text-gray-800">
            Cancel
        </button>
    </div>
</div>
