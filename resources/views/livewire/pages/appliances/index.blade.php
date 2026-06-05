<?php

declare(strict_types=1);

use App\Models\Appliance;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Collection $appliances;

    public function mount(): void
    {
        $household = Auth::user()->households()->first();
        abort_if(!$household, 403);

        $this->appliances = Appliance::where('household_id', $household->id)
            ->with('applianceType')
            ->withCount([
                'maintenanceTasks as overdue_count' => fn($q) => $q->where('next_due_at', '<', now())->whereNotNull('next_due_at'),
                'maintenanceTasks as due_soon_count' => fn($q) => $q->whereBetween('next_due_at', [now(), now()->addDays(30)])->whereNotNull('next_due_at'),
                'maintenanceTasks as upcoming_count' => fn($q) => $q->where('next_due_at', '>', now()->addDays(30))->whereNotNull('next_due_at'),
            ])
            ->orderByDesc('overdue_count')
            ->orderByDesc('due_soon_count')
            ->orderBy('name')
            ->get();
    }
}; ?>

<x-slot name="header">
    <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('My Appliances') }}
        </h2>
        <a href="{{ route('appliances.create') }}" wire:navigate
           class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
            {{ __('Add Appliance') }}
        </a>
    </div>
</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        @if($appliances->isEmpty())
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-12 text-center">
                    <p class="text-gray-500 text-lg mb-4">{{ __('No appliances yet.') }}</p>
                    <a href="{{ route('appliances.create') }}" wire:navigate
                       class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 transition ease-in-out duration-150">
                        {{ __('Add your first appliance') }}
                    </a>
                </div>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($appliances as $appliance)
                    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-5">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <h3 class="font-semibold text-gray-900 text-base">{{ $appliance->name }}</h3>
                                <p class="text-sm text-gray-500 mt-0.5">{{ $appliance->model }}</p>
                            </div>
                            @if($appliance->applianceType)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700 shrink-0 ml-2">
                                    {{ $appliance->applianceType->name }}
                                </span>
                            @endif
                        </div>

                        <div class="flex gap-3 text-xs mb-4">
                            @if($appliance->overdue_count > 0)
                                <span class="inline-flex items-center gap-1 text-red-600 font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                                    {{ $appliance->overdue_count }} overdue
                                </span>
                            @endif
                            @if($appliance->due_soon_count > 0)
                                <span class="inline-flex items-center gap-1 text-amber-600 font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                                    {{ $appliance->due_soon_count }} due soon
                                </span>
                            @endif
                            @if($appliance->upcoming_count > 0)
                                <span class="inline-flex items-center gap-1 text-gray-500">
                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span>
                                    {{ $appliance->upcoming_count }} upcoming
                                </span>
                            @endif
                            @if($appliance->overdue_count === 0 && $appliance->due_soon_count === 0 && $appliance->upcoming_count === 0)
                                <span class="text-gray-400">No tasks scheduled</span>
                            @endif
                        </div>

                        <div class="flex gap-4">
                            <a href="{{ route('appliances.show', $appliance) }}" wire:navigate
                               class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
                                {{ __('View details') }} &rarr;
                            </a>
                            <a href="{{ route('appliances.edit', $appliance) }}" wire:navigate
                               class="text-sm text-gray-500 hover:text-gray-700">
                                {{ __('Edit') }}
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

    </div>
</div>
