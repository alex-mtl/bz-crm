<x-filament-panels::page>
    @php
        $g = fn (string $key, array $replace = []): string => __('geo.ui.'.$key, $replace);
        $box = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10';
        $smallButton = 'rounded border border-gray-200 px-2 py-0.5 text-xs hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5';
    @endphp

    @if ($zone->isArchived())
        <div class="rounded-lg bg-gray-100 px-4 py-2 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $g('archived') }}</div>
    @endif

    <section class="{{ $box }}" data-test="zone-about">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="inline-block h-3 w-3 rounded-full" style="background: {{ $zone->color }}"></span>
            <x-filament::badge color="gray">{{ $zone->territory?->name() ?? $g('whole_organization') }}</x-filament::badge>
            @if ($zone->notify_events)
                <x-filament::badge color="info">{{ $g('notify_events') }}</x-filament::badge>
            @endif
            @if ($zone->notify_crossings)
                <x-filament::badge color="info">{{ $g('notify_crossings') }}</x-filament::badge>
            @endif
        </div>
        @if ($zone->description)
            <p class="mt-3 whitespace-pre-line text-sm">{{ $zone->description }}</p>
        @endif
        <p class="mt-3 text-sm"><span class="text-gray-500">{{ $g('responsibles') }}:</span>
            {{ $zone->responsibles->map(fn ($person) => $person->fullName())->implode(', ') ?: '—' }}</p>
    </section>

    {{-- The outline. Corners are dragged; a click on the map adds one, a double click on a corner removes it. --}}
    <section class="{{ $box }}" x-data="{ editing: @js($editing) }" data-test="zone-map">
        @if ($mayManage && ! $zone->isArchived())
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <x-filament::button size="sm" color="gray" icon="heroicon-o-pencil" x-show="! editing" data-test="outline-edit"
                    x-on:click="editing = true; window.dispatchEvent(new CustomEvent('bz-zone-edit'))">{{ $g('edit_outline') }}</x-filament::button>
                <x-filament::button size="sm" icon="heroicon-o-check" x-show="editing" x-cloak data-test="outline-save"
                    x-on:click="editing = false; window.dispatchEvent(new CustomEvent('bz-zone-save'))">{{ $g('save_outline') }}</x-filament::button>
                <x-filament::button size="sm" color="gray" x-show="editing" x-cloak
                    x-on:click="editing = false; window.dispatchEvent(new CustomEvent('bz-zone-cancel'))">{{ $g('cancel') }}</x-filament::button>
                <span class="text-xs text-gray-500" x-show="editing" x-cloak>{{ $g('outline_hint') }}</span>
            </div>
        @endif
        <div wire:ignore>
            <div class="bz-map" data-bz-map data-config='@json($map)'></div>
        </div>
        @vite('resources/js/field-map.js')
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- ФО §6.11: «мероприятие в геозоне X уведомляет ответственных за X». --}}
        <section class="{{ $box }}" data-test="zone-events">
            <h3 class="text-sm font-semibold">{{ $g('bound_events') }}</h3>
            <ul class="mt-2 space-y-1 text-sm">
                @forelse ($events as $event)
                    <li class="flex flex-wrap items-center gap-2">
                        <span class="text-xs text-gray-500">{{ $event->starts_at->isoFormat('L LT') }}</span>
                        <a class="underline" href="{{ \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $event]) }}">{{ $event->title }}</a>
                        @if ($mayManage)
                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('unlinkEvent', { event: {{ $event->id }} })">{{ $g('unlink') }}</button>
                        @endif
                    </li>
                @empty
                    <li class="text-gray-500">{{ $g('no_events') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- ФО §6.11: «все обходы внутри полигона … попадают в сводку». --}}
        @if ($figures !== null)
            <section class="{{ $box }}" data-test="zone-summary">
                <h3 class="text-sm font-semibold">{{ $g('canvass_inside') }}</h3>
                <dl class="mt-2 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">{{ $g('houses') }}</dt><dd>{{ $figures['houses'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ $g('visited') }}</dt><dd>{{ $figures['visited'] }} / {{ $figures['apartments'] }} · {{ $figures['visited_pct'] }}%</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ $g('supporters') }}</dt><dd>{{ $figures['supporters'] }} · {{ $figures['supporter_pct'] }}%</dd></div>
                </dl>
            </section>
        @endif
    </div>

    @if ($crossings !== [])
        <section class="{{ $box }}" data-test="zone-crossings">
            <h3 class="text-sm font-semibold">{{ $g('crossings') }}</h3>
            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-white/5">
                @foreach ($crossings as $row)
                    <li class="flex flex-wrap items-center gap-2 py-1.5">
                        <span class="w-28 text-xs text-gray-500">{{ $row['crossing']->occurred_at->isoFormat('L LT') }}</span>
                        <x-filament::badge :color="$row['crossing']->direction === 'enter' ? 'success' : 'gray'" size="sm">{{ $g('crossing_'.$row['crossing']->direction) }}</x-filament::badge>
                        <span>{{ $row['name'] }}</span>
                        <span class="text-xs text-gray-500">{{ $g('mover_'.$row['crossing']->mover_type) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-filament-panels::page>
