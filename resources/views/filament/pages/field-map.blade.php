<x-filament-panels::page>
    @php
        $g = fn (string $key, array $replace = []): string => __('geo.ui.'.$key, $replace);
        $box = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10';
        $smallButton = 'rounded border border-gray-200 px-2 py-0.5 text-xs hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5';
    @endphp

    <div wire:poll.30s="refreshMovers"></div>

    <div wire:ignore>
        <div class="bz-map" data-bz-map data-test="field-map" data-houses="{{ $houses }}" data-config='@json($map)'></div>
    </div>
    @vite('resources/js/field-map.js')

    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500" data-test="map-legend">
        <span>{{ $g('legend') }}:</span>
        @foreach (['#94a3b8' => 'legend_none', '#f59e0b' => 'legend_started', '#3b82f6' => 'legend_half', '#16a34a' => 'legend_done'] as $color => $label)
            <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $color }}"></span>{{ $g($label) }}</span>
        @endforeach
    </div>

    @if ($people !== [] || $vehicles !== [])
        <div class="grid gap-4 lg:grid-cols-2">
            @if ($people !== [])
                <section class="{{ $box }}" data-test="map-people">
                    <h3 class="text-sm font-semibold">{{ $g('sharing_now') }}</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($people as $person)
                            <li class="flex flex-wrap items-center gap-2">
                                <span>{{ $person['name'] }}</span>
                                <span class="text-xs text-gray-500">{{ $g('until', ['at' => \Illuminate\Support\Carbon::parse($person['until'])->isoFormat('LT')]) }}</span>
                                <button type="button" class="{{ $smallButton }}" wire:click="showTrack({{ $person['id'] }})">{{ $g('track') }}</button>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-gray-500">{{ $g('track_hint') }}</p>
                </section>
            @endif
            @if ($vehicles !== [])
                <section class="{{ $box }}" data-test="map-vehicles">
                    <h3 class="text-sm font-semibold">{{ $g('vehicles') }}</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($vehicles as $vehicle)
                            <li class="flex flex-wrap items-center gap-2">
                                <span>{{ $vehicle['name'] }} {{ $vehicle['plate'] }}</span>
                                <span class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($vehicle['at'])->isoFormat('L LT') }}</span>
                                <button type="button" class="{{ $smallButton }}" wire:click="showVehicleTrack({{ $vehicle['id'] }})">{{ $g('track') }}</button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
