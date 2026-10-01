<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        <div class="relative max-w-xl">
            <x-filament::input.wrapper>
                <x-filament::input type="search" wire:model.live.debounce.300ms="search" :placeholder="__('admin.simulator.search')" />
            </x-filament::input.wrapper>
            @if ($this->candidates !== [])
                <ul class="absolute z-10 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow dark:border-white/10 dark:bg-gray-900">
                    @foreach ($this->candidates as $id => $label)
                        <li><button type="button" wire:click="pick({{ $id }})" class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">{{ $label }}</button></li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($picture = $this->picture)
            <x-filament::section :heading="$picture['user']->person->fullName()" :description="__('admin.simulator.hint')">
                <div class="grid gap-6 md:grid-cols-2">
                    <div>
                        <h3 class="mb-2 text-sm font-semibold">{{ __('admin.simulator.roles') }}</h3>
                        <ul class="flex flex-col gap-1 text-sm" data-test="simulator-roles">
                            @forelse ($picture['roles'] as $row)
                                <li><span class="font-medium">{{ $row['role'] }}</span> — {{ $row['scope'] }}
                                    <span class="text-gray-500">({{ $row['kind'] }}@if ($row['until']), {{ __('admin.users.until', ['date' => $row['until']]) }}@endif)</span></li>
                            @empty
                                <li class="text-gray-500">—</li>
                            @endforelse
                        </ul>
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold">{{ __('admin.people.territorial_access') }}</h3>
                        <ul class="flex flex-col gap-1 text-sm" data-test="simulator-territories">
                            @forelse ($picture['territories'] as $row)
                                <li><span class="font-medium">{{ $row['territory'] }}</span> — {{ $row['origin'] }}
                                    @if ($row['until'])<span class="text-gray-500">({{ __('admin.users.until', ['date' => $row['until']]) }})</span>@endif</li>
                            @empty
                                <li class="text-gray-500">{{ __('admin.people.no_territories') }}</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section :heading="__('admin.simulator.rights')">
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($picture['codes'] as $module => $codes)
                        <div>
                            <h3 class="mb-1 text-sm font-semibold">{{ $module }}</h3>
                            <ul class="text-sm">
                                @foreach ($codes as $row)
                                    <li class="flex justify-between gap-2 border-b border-gray-100 py-1 dark:border-white/5">
                                        <span>{{ $row['label'] }} <code class="text-xs text-gray-500">{{ $row['code'] }}</code></span>
                                        <span class="text-right text-xs text-gray-500">{{ $row['scope'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
