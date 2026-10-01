<x-filament-panels::page>
    @php($n = fn (string $key): string => __('notifications.ui.'.$key))

    <div class="flex flex-wrap items-center gap-2" data-test="notification-toolbar">
        <label class="flex items-center gap-2 text-sm">
            <x-filament::input.checkbox wire:model.live="unread" /> {{ $n('unread_only') }} ({{ $unreadCount }})
        </label>
        @if ($categories !== [])
            <x-filament::input.wrapper class="w-72">
                <x-filament::input.select wire:model.live="category">
                    <option value="">{{ $n('all_categories') }}</option>
                    @foreach ($categories as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        @endif
        @if ($unreadCount > 0)
            <x-filament::button size="sm" color="gray" wire:click="markAllRead">{{ $n('mark_all_read') }}</x-filament::button>
        @endif
        <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-cog-6-tooth" href="{{ \App\Filament\Pages\NotificationSettings::getUrl() }}">{{ $n('settings') }}</x-filament::button>
    </div>

    <div class="flex max-w-3xl flex-col gap-2" data-test="notifications">
        @forelse ($items as $item)
            @php($data = $item->data)
            @php($critical = $item->category === 'critical')
            @php($needsAck = $critical && $pending->has((int) $item->subject_id))
            <article wire:key="notification-{{ $item->id }}" @class([
                'rounded-xl p-3 text-sm shadow-sm ring-1',
                'bg-white ring-gray-200 dark:bg-gray-900 dark:ring-white/10' => $item->read_at !== null,
                'bg-primary-50 ring-primary-200 dark:bg-primary-500/10 dark:ring-primary-500/30' => $item->read_at === null && ! $critical,
                'bg-danger-50 ring-danger-200 dark:bg-danger-500/10 dark:ring-danger-500/30' => $item->read_at === null && $critical,
            ])>
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="font-medium">{{ $data['title'] ?? '' }}</div>
                    <div class="text-xs text-gray-500">{{ $item->created_at->isoFormat('LLL') }}</div>
                </div>
                @if (filled($data['body'] ?? null))
                    <div class="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $data['body'] }}</div>
                @endif
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    @if ($needsAck)
                        <x-filament::button size="xs" color="danger" wire:click="acknowledge({{ (int) $item->subject_id }})">{{ __('notifications.acknowledge') }}</x-filament::button>
                    @elseif (filled($data['actions'][0]['url'] ?? null) && ! $critical && ! str_ends_with($data['actions'][0]['url'], '/notification-center'))
                        <x-filament::button size="xs" color="gray" wire:click="open('{{ $item->id }}')">{{ $data['actions'][0]['label'] ?? __('notifications.open') }}</x-filament::button>
                    @endif
                    @if ($item->read_at === null && ! $critical)
                        <button type="button" class="text-xs text-gray-500 underline" wire:click="markRead('{{ $item->id }}')">{{ $n('mark_read') }}</button>
                    @endif
                    @if ($critical && ! $needsAck)
                        <span class="text-xs text-gray-500">{{ $n('acknowledged') }}</span>
                    @endif
                </div>
            </article>
        @empty
            <p class="text-sm text-gray-500" data-test="notifications-empty">{{ $n('empty') }}</p>
        @endforelse

        @if ($hasMore)
            <div><x-filament::button color="gray" size="sm" wire:click="more">{{ __('social.ui.more') }}</x-filament::button></div>
        @endif
    </div>
</x-filament-panels::page>
