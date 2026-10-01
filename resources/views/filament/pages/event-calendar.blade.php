<x-filament-panels::page>
    @php($e = fn (string $key): string => __('events.ui.'.$key))

    <div class="flex flex-wrap items-center gap-2" data-test="calendar-toolbar">
        <x-filament::button size="sm" color="gray" wire:click="shift(-1)">‹</x-filament::button>
        <x-filament::button size="sm" color="gray" wire:click="$set('month', '{{ now()->format('Y-m') }}')">{{ __('admin.views.today') }}</x-filament::button>
        <x-filament::button size="sm" color="gray" wire:click="shift(1)">›</x-filament::button>

        <x-filament::input.wrapper class="ms-2 w-52">
            <x-filament::input.select wire:model.live="scope">
                <option value="personal">{{ $e('scopes.personal') }}</option>
                <option value="all">{{ $e('scopes.all') }}</option>
                @if ($this->groups !== [])
                    <option value="group">{{ $e('scopes.group') }}</option>
                @endif
                <option value="territory">{{ $e('scopes.territory') }}</option>
            </x-filament::input.select>
        </x-filament::input.wrapper>
        @if ($scope === 'group')
            <x-filament::input.wrapper class="w-56">
                <x-filament::input.select wire:model.live="groupId">
                    <option value="">—</option>
                    @foreach ($this->groups as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        @endif
        @if ($scope === 'territory')
            <x-filament::input.wrapper class="w-56">
                <x-filament::input.select wire:model.live="territoryId">
                    <option value="">—</option>
                    @foreach (\App\Domain\Geo\Models\Territory::query()->where('depth', '<=', 2)->orderBy('path')->get() as $territory)
                        <option value="{{ $territory->id }}">{{ str_repeat('— ', $territory->depth) }}{{ $territory->name() }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        @endif
        @if ($scope === 'personal')
            <label class="flex items-center gap-2 text-sm">
                <x-filament::input.checkbox wire:model.live="withTasks" /> {{ $e('with_tasks') }}
            </label>
        @endif
        <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-list-bullet" href="{{ \App\Filament\Resources\Events\EventResource::getUrl('index') }}">{{ $e('plural') }}</x-filament::button>
    </div>

    <div class="grid grid-cols-7 gap-px overflow-hidden rounded-xl bg-gray-200 text-sm dark:bg-white/10" data-test="event-calendar">
        @foreach ($this->days as $day)
            <div @class(['min-h-24 p-1.5', 'bg-white dark:bg-gray-900' => $day['inMonth'], 'bg-gray-50 text-gray-400 dark:bg-gray-950' => ! $day['inMonth']])>
                <div @class(['mb-1 text-xs', 'font-bold text-primary-600' => $day['date']->isToday()])>{{ $day['date']->isoFormat('dd D') }}</div>
                @foreach ($day['events'] as $event)
                    <a href="{{ \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $event]) }}" title="{{ $event->title }}" @class([
                        'mb-0.5 block truncate rounded px-1 text-xs',
                        'bg-primary-50 text-primary-800 dark:bg-primary-500/10 dark:text-primary-300' => ! $event->isCancelled(),
                        'bg-gray-100 text-gray-500 line-through dark:bg-white/5' => $event->isCancelled(),
                    ])>{{ $event->starts_at->format('H:i') }} {{ $event->title }}</a>
                @endforeach
                @foreach ($day['tasks'] as $task)
                    <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}" title="{{ $task->title }}" @class([
                        'mb-0.5 block truncate rounded px-1 text-xs',
                        'bg-danger-50 text-danger-700 dark:bg-danger-500/10' => $task->isOverdue(),
                        'bg-warning-50 text-warning-800 dark:bg-warning-500/10 dark:text-warning-300' => ! $task->isOverdue(),
                    ])>✓ {{ $task->title }}</a>
                @endforeach
            </div>
        @endforeach
    </div>

    <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10" data-test="calendar-feeds">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold">{{ $e('feeds') }}</h3>
            {{ $this->subscribeAction }}
        </div>
        <p class="mt-1 text-xs text-gray-500">{{ $e('feeds_hint') }}</p>
        @foreach ($this->feeds as $feed)
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-2 text-sm dark:border-white/5" wire:key="feed-{{ $feed->id }}">
                <div>
                    {{ $this->feedLabel($feed) }}
                    <span class="text-xs text-gray-500">· {{ $feed->created_at->isoFormat('LL') }}@if ($feed->last_used_at) · {{ $e('feed_last_used') }} {{ $feed->last_used_at->diffForHumans() }}@endif</span>
                </div>
                <button type="button" class="rounded border border-gray-200 px-2 py-0.5 text-xs text-danger-600 hover:bg-gray-50 dark:border-white/10"
                        wire:click="revokeFeed({{ $feed->id }})" wire:confirm="{{ $e('feed_revoke_confirm') }}">{{ $e('feed_revoke') }}</button>
            </div>
        @endforeach
    </section>
</x-filament-panels::page>
