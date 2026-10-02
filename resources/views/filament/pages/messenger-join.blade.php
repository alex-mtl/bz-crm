<x-filament-panels::page>
    <div class="max-w-xl rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10" data-test="messenger-join">
        @if ($this->invitation)
            <h2 class="text-lg font-semibold">{{ $this->invitation->chat->title }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('messaging.ui.types.group') }}</p>
            <div class="mt-4">
                <x-filament::button wire:click="join" icon="heroicon-o-user-plus">{{ __('messaging.ui.join') }}</x-filament::button>
            </div>
        @else
            <p class="text-sm text-gray-500">{{ __('messaging.errors.link_unusable') }}</p>
        @endif
    </div>
</x-filament-panels::page>
