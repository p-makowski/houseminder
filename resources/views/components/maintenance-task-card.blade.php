@props([
    'task',
    'color'             => 'gray',
    'showDraftBadge'    => false,
    'showDescription'   => false,
    'showApplianceName' => false, // requires appliance relation eager-loaded by caller
])

@php
$colorClasses = [
    'red'    => ['border' => 'border-red-200',    'date' => 'text-red-600'],
    'yellow' => ['border' => 'border-yellow-200', 'date' => 'text-yellow-600'],
    'blue'   => ['border' => 'border-blue-200',   'date' => 'text-blue-600'],
    'gray'   => ['border' => 'border-gray-200',   'date' => 'text-gray-500'],
];
$c = $colorClasses[$color] ?? $colorClasses['gray'];
@endphp

<div class="bg-white border {{ $c['border'] }} rounded-md p-4">
    {{-- Header: task name (with optional appliance prefix) + optional draft badge --}}
    <div>
        @if($showApplianceName)
            <p class="font-medium text-gray-900">{{ $task->appliance?->name }} — {{ $task->name }}</p>
        @else
            <p class="font-medium text-gray-900">{{ $task->name }}</p>
        @endif
        @if($showDraftBadge && !$task->is_confirmed)
            <span class="text-xs text-amber-600 border border-amber-200 bg-amber-50 rounded px-1.5 py-0.5 mt-0.5 inline-block">Draft</span>
        @endif
    </div>

    {{-- Optional description --}}
    @if($showDescription && $task->description)
        <p class="text-sm text-gray-500 mt-1">{{ $task->description }}</p>
    @endif

    {{-- Meta row: interval + last done --}}
    <p class="text-xs text-gray-400 mt-2">
        Every {{ $task->interval_value }} {{ Str::plural($task->interval_unit, $task->interval_value) }}
        @if($task->last_completed_at)
            · <span title="{{ $task->last_completed_at->format('M j, Y') }}">Last done {{ $task->last_completed_at->diffForHumans() }}</span>
        @else
            · Never done
        @endif
    </p>

    {{-- Footer: due date left + actions slot right --}}
    <div class="flex justify-between items-center mt-3">
        @if($task->next_due_at)
            <span class="text-xs {{ $c['date'] }}">Due {{ $task->next_due_at->format('M j, Y') }}</span>
        @else
            <span></span>
        @endif
        <div class="flex items-center gap-3">
            {{ $actions ?? '' }}
        </div>
    </div>
</div>
