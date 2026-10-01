<div class="flex flex-col gap-2" data-test="my-invitations">
    @forelse ($invitations as $invitation)
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5" wire:key="my-invitation-{{ $invitation->id }}">
            <div>
                <div class="font-medium">{{ $invitation->group->name }} · {{ __('groups.types.'.$invitation->group->type) }}</div>
                <div class="text-xs text-gray-500">{{ __('groups.ui.invited_by', ['name' => $invitation->inviter?->fullName() ?? '—']) }}</div>
            </div>
            <div class="flex gap-1">
                <x-filament::button size="xs" wire:click="answerInvitation({{ $invitation->id }}, true)">{{ __('groups.ui.accept') }}</x-filament::button>
                <x-filament::button size="xs" color="gray" wire:click="answerInvitation({{ $invitation->id }}, false)">{{ __('groups.ui.decline') }}</x-filament::button>
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500">{{ __('groups.ui.no_invitations') }}</p>
    @endforelse
</div>
