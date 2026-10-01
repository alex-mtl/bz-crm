<x-filament-panels::page>
    <div class="flex items-center gap-2">
        <x-filament::button size="sm" color="gray" wire:click="shift(-1)">‹</x-filament::button>
        <x-filament::button size="sm" color="gray" wire:click="$set('month', '{{ now()->format('Y-m') }}')">{{ __('admin.views.today') }}</x-filament::button>
        <x-filament::button size="sm" color="gray" wire:click="shift(1)">›</x-filament::button>
        <label class="ms-4 flex items-center gap-2 text-sm">
            <x-filament::input.checkbox wire:model.live="onlyMine" /> {{ __('admin.tasks.tabs.mine') }}
        </label>
    </div>

    <div class="grid grid-cols-7 gap-px overflow-hidden rounded-xl bg-gray-200 text-sm dark:bg-white/10" data-test="task-calendar">
        @foreach ($this->days as $day)
            <div @class(['min-h-24 p-1.5', 'bg-white dark:bg-gray-900' => $day['inMonth'], 'bg-gray-50 text-gray-400 dark:bg-gray-950' => ! $day['inMonth']])>
                <div @class(['mb-1 text-xs', 'font-bold text-primary-600' => $day['date']->isToday()])>{{ $day['date']->isoFormat('dd D') }}</div>
                @foreach ($day['tasks'] as $task)
                    <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}"
                       @class(['mb-0.5 block truncate rounded px-1 text-xs', 'bg-danger-50 text-danger-700' => $task->isOverdue(), 'bg-primary-50 text-primary-800 dark:bg-primary-500/10 dark:text-primary-300' => ! $task->isOverdue() && ! $task->isClosed(), 'bg-gray-100 text-gray-500 line-through dark:bg-white/5' => $task->isClosed()])
                       title="{{ $task->title }}">{{ $task->due_at?->format('H:i') }} {{ $task->title }}</a>
                @endforeach
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
