<x-filament-panels::page>
    <div class="max-w-xl rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10" data-test="group-join">
        @if ($this->invitation)
            <h2 class="text-lg font-semibold">{{ $this->invitation->group->name }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('groups.types.'.$this->invitation->group->type) }}</p>
            @if ($this->invitation->group->description)
                <p class="mt-3 whitespace-pre-line text-sm">{{ $this->invitation->group->description }}</p>
            @endif
            <div class="mt-4">
                <x-filament::button wire:click="join" icon="heroicon-o-user-plus">{{ __('groups.ui.join') }}</x-filament::button>
            </div>
        @else
            <p class="text-sm text-gray-500">{{ __('groups.errors.invitation_unusable') }}</p>
        @endif
    </div>
</x-filament-panels::page>
