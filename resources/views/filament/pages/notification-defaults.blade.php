<x-filament-panels::page>
    @php($n = fn (string $key): string => __('notifications.ui.'.$key))

    <div class="flex flex-wrap items-center gap-2">
        <x-filament::input.wrapper class="w-80">
            <x-filament::input.select wire:model.live="role">
                @foreach ($roles as $code => $name)
                    <option value="{{ $code }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <span class="text-sm text-gray-500">{{ $n('defaults_hint') }}</span>
    </div>

    <div class="max-w-3xl overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10" data-test="notification-defaults">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 text-left dark:bg-white/5">
                    <th class="px-3 py-2 font-semibold">{{ $n('category') }}</th>
                    @foreach ($channels as $channel)
                        <th class="px-3 py-2 font-semibold">{{ __('notifications.channels.'.$channel) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($categories as $code => $category)
                    <tr class="border-t border-gray-100 dark:border-white/5" wire:key="default-{{ $role }}-{{ $code }}">
                        <td class="px-3 py-2">{{ $category['label'] }}</td>
                        @foreach ($channels as $channel)
                            <td class="px-3 py-2">
                                @if ($category['mandatory'])
                                    <span class="text-xs text-gray-500">{{ $n('mandatory') }}</span>
                                @else
                                    @php($value = array_key_exists($channel, $stored[$code] ?? []) ? ($stored[$code][$channel] ? 'on' : 'off') : '')
                                    <select class="rounded-lg border-gray-300 py-1 text-xs dark:border-white/10 dark:bg-white/5"
                                            wire:change="setDefault('{{ $code }}', '{{ $channel }}', $event.target.value)">
                                        <option value="" @selected($value === '')>
                                            {{ $n('as_category') }} ({{ in_array($channel, $category['channels'], true) ? $n('on') : $n('off') }})
                                        </option>
                                        <option value="on" @selected($value === 'on')>{{ $n('on') }}</option>
                                        <option value="off" @selected($value === 'off')>{{ $n('off') }}</option>
                                    </select>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
