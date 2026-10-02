<x-filament-panels::page>
    @php($m = fn (string $key): string => __('messaging.ui.'.$key))

    <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10" data-test="direct-rules">
        <h3 class="text-sm font-semibold">{{ $m('direct_rules') }}</h3>
        <p class="mt-1 max-w-3xl text-xs text-gray-500">{{ $m('direct_rules_hint') }}</p>
        <div class="mt-3 overflow-x-auto">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th class="p-1 text-left font-medium text-gray-500">{{ $m('sender') }} ↓ / {{ $m('recipient') }} →</th>
                        @foreach ($roles as $recipient)
                            <th class="p-1 font-medium" title="{{ $recipient->name() }}"><span class="block max-w-20 truncate">{{ $recipient->name() }}</span></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($roles as $sender)
                        <tr class="border-t border-gray-100 dark:border-white/5" wire:key="rule-{{ $sender->id }}">
                            <td class="p-1 font-medium">{{ $sender->name() }}</td>
                            @foreach ($roles as $recipient)
                                <td class="p-1 text-center">
                                    <input type="checkbox" class="rounded border-gray-300 text-primary-600 dark:border-white/20 dark:bg-white/5"
                                           @checked($matrix[$sender->id][$recipient->id]) wire:click="toggle({{ $sender->id }}, {{ $recipient->id }})" />
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="max-w-xl rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10" data-test="retention">
        <h3 class="text-sm font-semibold">{{ $m('retention') }}</h3>
        <p class="mt-1 text-xs text-gray-500">{{ $m('retention_hint') }}</p>
        <form wire:submit="saveRetention" class="mt-3 flex items-end gap-2">
            <x-filament::input.wrapper class="w-40">
                <x-filament::input type="number" min="1" max="600" wire:model="retentionMonths" placeholder="{{ $m('retention_forever') }}" />
            </x-filament::input.wrapper>
            <span class="pb-2 text-sm text-gray-500">{{ $m('months') }}</span>
            <x-filament::button type="submit" size="sm">{{ $m('save') }}</x-filament::button>
        </form>
        <p class="mt-2 text-xs text-gray-500">{{ $m('retention_now') }}: {{ $current ? $current.' '.$m('months') : $m('retention_forever') }}</p>
    </section>
</x-filament-panels::page>
