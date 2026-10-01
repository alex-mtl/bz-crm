<x-filament-panels::page>
    @php($n = fn (string $key): string => __('notifications.ui.'.$key))

    <p class="max-w-3xl text-sm text-gray-500">{{ $n('settings_hint') }}</p>

    <div class="max-w-3xl overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10" data-test="notification-settings">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 text-left dark:bg-white/5">
                    <th class="px-3 py-2 font-semibold">{{ $n('category') }}</th>
                    @foreach ($channels as $channel)
                        <th class="px-3 py-2 text-center font-semibold">{{ __('notifications.channels.'.$channel) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($categories as $code => $category)
                    <tr class="border-t border-gray-100 dark:border-white/5" wire:key="category-{{ $code }}">
                        <td class="px-3 py-2">
                            {{ $category['label'] }}
                            @if ($category['mandatory'])
                                <span class="text-xs text-gray-500">· {{ $n('mandatory') }}</span>
                            @endif
                        </td>
                        @foreach ($channels as $channel)
                            <td class="px-3 py-2 text-center">
                                @if ($channel === 'push')
                                    <span class="text-xs text-gray-400" title="{{ $n('push_later') }}">—</span>
                                @else
                                    <input type="checkbox" class="rounded border-gray-300 text-primary-600 disabled:opacity-50 dark:border-white/20 dark:bg-white/5"
                                           @checked($matrix[$code][$channel] ?? false) @disabled($category['mandatory'])
                                           wire:click="toggle('{{ $code }}', '{{ $channel }}')" />
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="max-w-3xl text-xs text-gray-500">{{ $n('push_later') }}</p>

    <div>
        <x-filament::button size="sm" color="gray" wire:click="resetToDefaults" wire:confirm="{{ $n('reset_confirm') }}">{{ $n('reset') }}</x-filament::button>
    </div>
</x-filament-panels::page>
