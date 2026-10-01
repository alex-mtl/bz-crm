<x-filament-panels::page>
    <x-filament::input.wrapper class="w-80">
        <x-filament::input.select wire:model.live="projectId">
            <option value="">{{ __('admin.views.timeline') }}</option>
            @foreach ($this->projects as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>

    @if ($chart = $this->chart)
        <div class="overflow-x-auto rounded-xl bg-white p-3 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10" data-test="gantt">
            <div class="min-w-[720px]">
                <div class="relative mb-2 ms-64 h-5 border-b border-gray-200 text-xs text-gray-500 dark:border-white/10">
                    @foreach ($chart['months'] as $month)
                        <span class="absolute" style="left: {{ $month['left'] }}%">{{ $month['label'] }}</span>
                    @endforeach
                </div>
                @forelse ($chart['rows'] as $row)
                    <div class="flex items-center gap-2 py-0.5 text-sm">
                        <div @class(['w-64 shrink-0 truncate', 'ps-4 text-gray-600 dark:text-gray-300' => $row['level'] > 0, 'font-semibold' => $row['level'] === 0]) title="{{ $row['label'] }}">
                            @if ($row['url'])<a href="{{ $row['url'] }}" class="hover:underline">{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif
                        </div>
                        <div class="relative h-5 flex-1 rounded bg-gray-50 dark:bg-white/5">
                            @if ($row['width'] > 0)
                                <div @class([
                                    'absolute top-0.5 h-4 rounded',
                                    'bg-success-500/70' => $row['color'] === 'success',
                                    'bg-danger-500/70' => $row['color'] === 'danger',
                                    'bg-warning-500/70' => $row['color'] === 'warning',
                                    'bg-primary-500/70' => $row['color'] === 'primary',
                                    'bg-info-500/70' => $row['color'] === 'info',
                                    'bg-gray-400/70' => $row['color'] === 'gray',
                                ]) style="left: {{ $row['left'] }}%; width: {{ $row['width'] }}%"></div>
                            @endif
                        </div>
                        <div class="w-56 shrink-0 truncate text-xs text-gray-500" title="{{ $row['note'] }}">{{ $row['note'] }}</div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('admin.views.nothing') }}</p>
                @endforelse
            </div>
        </div>
    @endif
</x-filament-panels::page>
