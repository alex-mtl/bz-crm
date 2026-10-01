<x-filament-panels::page>
    @php
        $ui = fn (string $key, array $replace = []): string => __('groups.ui.'.$key, $replace);
        $smallButton = 'rounded border border-gray-200 px-2 py-0.5 text-xs hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5';
        $box = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10';
    @endphp

    <section class="{{ $box }}" data-test="group-about">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <x-filament::badge :color="match ($group->type) { 'open' => 'success', 'closed' => 'warning', default => 'danger' }">
                {{ __('groups.types.'.$group->type) }}
            </x-filament::badge>
            @if ($group->archived_at)
                <x-filament::badge color="gray">{{ $ui('archived') }}</x-filament::badge>
            @endif
            @if ($role)
                <span class="text-gray-500">{{ $ui('my_role') }}: {{ __('groups.roles.'.$role) }}</span>
            @endif
            @if ($group->org_unit_id)
                <span class="text-gray-500">· {{ \App\Filament\Support\Places::unitName($group->org_unit_id) }}</span>
            @endif
            @if ($group->territory_id)
                <span class="text-gray-500">· {{ \App\Filament\Support\Places::territoryName($group->territory_id) }}</span>
            @endif
            @if ($projectName)
                <span class="text-gray-500">· {{ __('admin.projects.singular') }}: {{ $projectName }}</span>
            @endif
        </div>
        @if ($group->description)
            <p class="mt-3 whitespace-pre-line text-sm">{{ $group->description }}</p>
        @endif
        @if ($group->rules)
            <h3 class="mt-3 text-sm font-semibold">{{ $ui('rules') }}</h3>
            <p class="whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $group->rules }}</p>
        @endif
        @if ($role)
            <div class="mt-3">
                <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-newspaper" href="{{ $feedUrl }}">
                    {{ $ui('feed') }} ({{ $postCount }})
                </x-filament::button>
            </div>
        @endif
    </section>

    @if ($requests->isNotEmpty())
        <section class="{{ $box }}" data-test="group-requests">
            <h3 class="mb-2 text-sm font-semibold">{{ $ui('requests') }} ({{ $requests->count() }})</h3>
            @foreach ($requests as $request)
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 py-2 text-sm dark:border-white/5" wire:key="request-{{ $request->id }}">
                    <div>
                        <span class="font-medium">{{ $request->person->fullName() }}</span>
                        @if ($request->message)
                            <span class="text-gray-500">— {{ $request->message }}</span>
                        @endif
                    </div>
                    <div class="flex gap-1">
                        <button type="button" class="{{ $smallButton }}" wire:click="decide({{ $request->id }}, true)">{{ $ui('approve') }}</button>
                        <button type="button" class="{{ $smallButton }}" wire:click="decide({{ $request->id }}, false)">{{ $ui('reject') }}</button>
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    @if ($invitations->isNotEmpty())
        <section class="{{ $box }}" data-test="group-invitations">
            <h3 class="mb-2 text-sm font-semibold">{{ $ui('pending_invitations') }} ({{ $invitations->count() }})</h3>
            @foreach ($invitations as $invitation)
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 py-2 text-sm dark:border-white/5" wire:key="invitation-{{ $invitation->id }}">
                    <div>
                        @if ($invitation->isLink())
                            {{ $ui('invite_link') }} · {{ $invitation->uses }}{{ $invitation->max_uses ? ' / '.$invitation->max_uses : '' }}
                            @if ($invitation->expires_at) · {{ $ui('link_expires') }}: {{ $invitation->expires_at->isoFormat('LLL') }} @endif
                        @else
                            {{ $invitation->person?->fullName() }}
                        @endif
                    </div>
                    <button type="button" class="{{ $smallButton }}" wire:click="revoke({{ $invitation->id }})">{{ $ui('revoke') }}</button>
                </div>
            @endforeach
        </section>
    @endif

    <section class="{{ $box }}" data-test="group-members">
        <h3 class="mb-2 text-sm font-semibold">{{ $ui('members') }} ({{ $members->count() }})</h3>
        @foreach ($members as $member)
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 py-2 text-sm dark:border-white/5" wire:key="member-{{ $member->id }}">
                <div>
                    <span class="font-medium">{{ $member->person->fullName() }}</span>
                    <span class="text-gray-500">· {{ $roles[$member->role] }}</span>
                </div>
                @if ($manages && $member->role !== 'owner' && ! $group->archived_at)
                    <div class="flex flex-wrap gap-1">
                        @foreach ($roles as $code => $label)
                            @continue($code === $member->role)
                            @continue(! $ownerLike && ($code === 'owner' || $code === 'admin' || $member->role === 'admin'))
                            <button type="button" class="{{ $smallButton }}" wire:click="setRole({{ $member->person_id }}, '{{ $code }}')"
                                    @if ($code === 'owner') wire:confirm="{{ $ui('transfer_confirm') }}" @endif>→ {{ $label }}</button>
                        @endforeach
                        @if ($ownerLike || $member->role !== 'admin')
                            <button type="button" class="{{ $smallButton }} text-danger-600" wire:click="removeMember({{ $member->person_id }})"
                                    wire:confirm="{{ $ui('remove_confirm') }}">{{ $ui('remove') }}</button>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </section>

    @if ($role)
        <section class="{{ $box }}" data-test="group-files">
            <h3 class="mb-2 text-sm font-semibold">{{ $ui('files') }}</h3>
            <div class="flex flex-wrap gap-2">
                @forelse ($files as $file)
                    <a href="{{ route('social.attachment', $file) }}" class="{{ $smallButton }}">📎 {{ $file->original_name }}</a>
                @empty
                    <p class="text-sm text-gray-500">{{ $ui('no_files') }}</p>
                @endforelse
            </div>
        </section>

        <section class="{{ $box }}" data-test="group-chat">
            <h3 class="mb-2 text-sm font-semibold">{{ $ui('chat') }}</h3>
            @livewire(\App\Livewire\GroupDiscussion::class, ['groupId' => $group->id], key('group-chat-'.$group->id))
        </section>
    @endif
</x-filament-panels::page>
