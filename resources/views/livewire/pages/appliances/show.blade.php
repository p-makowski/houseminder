<?php

declare(strict_types=1);

use App\Models\Appliance;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Appliance $appliance;

    public function mount(Appliance $appliance): void
    {
        $household = Auth::user()->households()->first();
        abort_if(!$household || $appliance->household_id !== $household->id, 403);

        $appliance->load(['applianceType', 'maintenanceTasks']);
        $this->appliance = $appliance;
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

    <h2 class="text-lg font-semibold text-gray-800 mb-3">Maintenance Plan</h2>

    @if($appliance->maintenanceTasks->isEmpty())
        <p class="text-gray-500">No maintenance tasks.</p>
    @else
        <div class="space-y-3">
            @foreach($appliance->maintenanceTasks as $task)
                <div class="border border-gray-200 rounded-md p-4">
                    <div class="flex justify-between items-start">
                        <h3 class="font-medium text-gray-900">{{ $task->name }}</h3>
                        @if($task->next_due_at)
                            <span class="text-xs text-gray-500">
                                Due {{ $task->next_due_at->format('M j, Y') }}
                            </span>
                        @endif
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
</div>
