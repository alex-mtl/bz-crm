<x-filament-panels::page>
    @php
        $g = fn (string $key): string => __('geo.ui.'.$key);
        $box = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10';
        $input = 'mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5';
        $label = 'block text-sm font-medium';
    @endphp

    <form wire:submit="save" class="space-y-4">
        <section class="{{ $box }}">
            <h3 class="text-sm font-semibold">{{ $g('settings_map') }}</h3>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <label class="{{ $label }}">{{ $g('map_provider') }}
                    <select class="{{ $input }}" wire:model.live="values.map_provider" data-test="map-provider">
                        @foreach ($providers as $provider)
                            <option value="{{ $provider }}">{{ $g('provider_'.$provider) }}</option>
                        @endforeach
                    </select>
                </label>
                @if (($values['map_provider'] ?? null) === 'custom')
                    <label class="{{ $label }}">{{ $g('map_url') }}
                        <input type="text" class="{{ $input }}" wire:model="values.map_url" placeholder="https://…/{z}/{x}/{y}.png">
                    </label>
                    <label class="{{ $label }}">{{ $g('map_attribution') }}
                        <input type="text" class="{{ $input }}" wire:model="values.map_attribution">
                    </label>
                @endif
                <label class="{{ $label }}">{{ $g('center_latitude') }}
                    <input type="number" step="0.000001" class="{{ $input }}" wire:model="values.map_latitude">
                </label>
                <label class="{{ $label }}">{{ $g('center_longitude') }}
                    <input type="number" step="0.000001" class="{{ $input }}" wire:model="values.map_longitude">
                </label>
                <label class="{{ $label }}">{{ $g('map_zoom') }}
                    <input type="number" min="3" max="19" class="{{ $input }}" wire:model="values.map_zoom">
                </label>
            </div>
        </section>

        <section class="{{ $box }}">
            <h3 class="text-sm font-semibold">{{ $g('settings_canvass') }}</h3>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <label class="{{ $label }}">{{ $g('houses_per_agitator') }}
                    <input type="number" min="0" class="{{ $input }}" wire:model="values.houses_per_agitator" data-test="houses-per-agitator">
                    <span class="mt-1 block text-xs font-normal text-gray-500">{{ $g('houses_per_agitator_hint') }}</span>
                </label>
                <label class="{{ $label }}">{{ $g('default_note_visibility') }}
                    <select class="{{ $input }}" wire:model="values.default_note_visibility">
                        <option value="team">{{ $g('visibility_team') }}</option>
                        <option value="personal">{{ $g('visibility_personal') }}</option>
                    </select>
                </label>
            </div>
        </section>

        <section class="{{ $box }}">
            <h3 class="text-sm font-semibold">{{ $g('settings_location') }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ $g('settings_location_hint') }}</p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <label class="{{ $label }}">{{ $g('share_minutes') }}
                    <input type="text" class="{{ $input }}" wire:model="shareMinutes" placeholder="60, 240, 480">
                </label>
                <label class="{{ $label }}">{{ $g('location_retention_days') }}
                    <input type="number" min="1" max="365" class="{{ $input }}" wire:model="values.location_retention_days">
                </label>
            </div>
        </section>

        <x-filament::button type="submit">{{ __('admin.save') }}</x-filament::button>
    </form>
</x-filament-panels::page>
