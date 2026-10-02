<x-filament-panels::page>
    @php
        $m = fn (string $key, array $replace = []): string => __('messaging.ui.'.$key, $replace);
        $smallButton = 'rounded border border-gray-200 px-1.5 py-0.5 text-xs hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5';
        $box = 'rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10';
    @endphp

    {{-- Real-time (ADR-012): a WebSocket event says "something changed" — the page then reloads through the server.
         Where there is no WebSocket server, the page polls. --}}
    <div wire:poll.20s
         x-data
         x-init="window.Echo && window.Echo.private('messenger.{{ $actor->id }}').listen('.chat.updated', () => $wire.$refresh())"
         class="grid gap-4 lg:grid-cols-[20rem_1fr]" data-test="messenger">

        <aside class="{{ $box }} flex flex-col gap-2 p-3" data-test="chat-list">
            <div class="flex flex-wrap gap-1">
                <x-filament::button size="xs" icon="heroicon-o-user" wire:click="mountAction('dialog')">{{ $m('new_dialog') }}</x-filament::button>
                @if ($mayStartGroup)
                    <x-filament::button size="xs" color="gray" icon="heroicon-o-user-group" wire:click="mountAction('group')">{{ $m('new_group') }}</x-filament::button>
                @endif
            </div>
            <x-filament::input.wrapper>
                <x-filament::input type="search" wire:model.live.debounce.400ms="chatSearch" placeholder="{{ $m('find_chat') }}" />
            </x-filament::input.wrapper>
            <x-filament::input.wrapper>
                <x-filament::input type="search" wire:model.live.debounce.500ms="search" placeholder="{{ $chat ? $m('search_in_chat') : $m('search_messages') }}" />
            </x-filament::input.wrapper>

            <div class="flex max-h-[60vh] flex-col gap-1 overflow-y-auto">
                @forelse ($chats as $row)
                    <button type="button" wire:key="chat-{{ $row['chat']->id }}" wire:click="open({{ $row['chat']->id }})" @class([
                        'flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-left text-sm',
                        'bg-primary-50 dark:bg-primary-500/10' => $chat?->id === $row['chat']->id,
                        'hover:bg-gray-50 dark:hover:bg-white/5' => $chat?->id !== $row['chat']->id,
                    ])>
                        <span class="min-w-0">
                            <span class="block truncate font-medium">
                                @if ($row['chat']->isGroup()) 👥 @elseif ($row['chat']->isSubject()) 📌 @endif
                                {{ $row['title'] }}
                            </span>
                            <span class="block truncate text-xs text-gray-500">
                                {{ $row['chat']->last_message_at?->diffForHumans() }}
                                @if ($row['member']?->draft) · {{ $m('draft') }} @endif
                                @if ($row['member']?->notify === 'mute') · 🔕 @endif
                            </span>
                        </span>
                        @if ($row['unread'] > 0)
                            <span class="rounded-full bg-primary-600 px-2 text-xs text-white" data-test="unread">{{ $row['unread'] }}</span>
                        @endif
                    </button>
                @empty
                    <p class="text-sm text-gray-500">{{ $m('no_chats') }}</p>
                @endforelse
            </div>
        </aside>

        <section class="{{ $box }} flex min-h-[60vh] flex-col p-3" data-test="chat">
            @if ($found !== null)
                <div class="mb-3" data-test="search-results">
                    <h3 class="mb-1 text-sm font-semibold">{{ $m('found') }}: {{ $found->count() }}</h3>
                    @foreach ($found as $hit)
                        <button type="button" class="block w-full rounded-lg px-2 py-1 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5"
                                wire:click="jump({{ $hit->chat_id }}, {{ $hit->id }})" wire:key="hit-{{ $hit->id }}">
                            <span class="text-xs text-gray-500">{{ $reader->title($actor, $hit->chat) }} · {{ $hit->author->fullName() }} · {{ $hit->created_at->isoFormat('LLL') }}</span>
                            <span class="block truncate">{{ $hit->body }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            @if (! $chat)
                <p class="m-auto text-sm text-gray-500" data-test="no-chat">{{ $m('choose_chat') }}</p>
            @else
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-2 dark:border-white/5">
                    <div>
                        <h2 class="font-semibold">{{ $title }}</h2>
                        <div class="text-xs text-gray-500">
                            {{ $m('types.'.$chat->type) }} · {{ trans_choice('messaging.ui.member_count', $members->count()) }}
                            @if ($subjectUrl) · <a class="underline" href="{{ $subjectUrl }}">{{ $m('open_subject') }}</a> @endif
                            @if ($chat->archived_at) · {{ $m('archived') }} @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <select class="rounded-lg border-gray-300 py-1 text-xs dark:border-white/10 dark:bg-white/5" wire:change="setNotify($event.target.value)" title="{{ $m('notify') }}">
                            @foreach (['all', 'mentions', 'mute'] as $level)
                                <option value="{{ $level }}" @selected(($member?->notify ?? 'all') === $level)>{{ $m('notify_levels.'.$level) }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="{{ $smallButton }}" wire:click="$toggle('showMembers')">{{ $m('members') }}</button>
                        @if ($mayManage)
                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('addMembers')">{{ $m('add_members') }}</button>
                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('link')">{{ $m('invite_link') }}</button>
                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('rename')">{{ $m('rename') }}</button>
                        @endif
                        @if ($chat->isGroup() && $member && $member->role !== 'owner')
                            <button type="button" class="{{ $smallButton }}" wire:click="leave" wire:confirm="{{ $m('leave_confirm') }}">{{ $m('leave') }}</button>
                        @endif
                    </div>
                </header>

                @if ($showMembers)
                    <div class="border-b border-gray-100 py-2 text-sm dark:border-white/5" data-test="chat-members">
                        @foreach ($members as $person)
                            <div class="flex flex-wrap items-center justify-between gap-2 py-0.5" wire:key="member-{{ $person->id }}">
                                <span>{{ $person->person->fullName() }} @if ($chat->isGroup()) <span class="text-xs text-gray-500">· {{ __('groups.roles.'.$person->role) }}</span> @endif</span>
                                @if ($mayManage && $person->role !== 'owner' && $person->person_id !== $actor->person_id)
                                    <span class="flex flex-wrap gap-1">
                                        @foreach (['admin', 'moderator', 'member'] as $role)
                                            @continue($role === $person->role)
                                            <button type="button" class="{{ $smallButton }}" wire:click="setRole({{ $person->person_id }}, '{{ $role }}')">→ {{ __('groups.roles.'.$role) }}</button>
                                        @endforeach
                                        <button type="button" class="{{ $smallButton }} text-danger-600" wire:click="removeMember({{ $person->person_id }})" wire:confirm="{{ __('groups.ui.remove_confirm') }}">{{ __('groups.ui.remove') }}</button>
                                    </span>
                                @endif
                            </div>
                        @endforeach
                        @foreach ($links as $link)
                            <div class="flex items-center justify-between gap-2 py-0.5 text-xs text-gray-500" wire:key="link-{{ $link->id }}">
                                <span>🔗 {{ $m('invite_link') }} · {{ $link->uses }}{{ $link->max_uses ? ' / '.$link->max_uses : '' }}
                                    @if ($link->expires_at) · {{ $link->expires_at->isoFormat('LLL') }} @endif
                                    @if (! $link->isUsable()) · {{ $m('link_dead') }} @endif</span>
                                <button type="button" class="{{ $smallButton }}" wire:click="revokeLink({{ $link->id }})">{{ __('groups.ui.revoke') }}</button>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($pinned->isNotEmpty())
                    <div class="border-b border-gray-100 py-2 text-sm dark:border-white/5" data-test="pinned">
                        @foreach ($pinned as $pin)
                            <a href="#message-{{ $pin->id }}" class="block truncate text-xs">📌 <span class="text-gray-500">{{ $pin->author->fullName() }}:</span> {{ $pin->body }}</a>
                        @endforeach
                    </div>
                @endif

                <div class="flex flex-1 flex-col gap-1.5 overflow-y-auto py-3" data-test="messages">
                    @if ($hasMore)
                        <div><button type="button" class="{{ $smallButton }}" wire:click="more">{{ $m('earlier') }}</button></div>
                    @endif
                    @forelse ($messages as $message)
                        @continue($message->root_id !== null && ($collapsed[$message->root_id] ?? false))
                        @php
                            $mine = $message->author_person_id === $actor->person_id;
                            $deleted = $message->isDeleted();
                            $scheduled = $message->status === 'scheduled';
                            $count = $message->parent_id === null ? ($replies[$message->id] ?? 0) : 0;
                        @endphp
                        <article id="message-{{ $message->id }}" wire:key="message-{{ $message->id }}" data-test="message"
                                 style="margin-inline-start: {{ min($message->depth, 8) * 1.25 }}rem" @class([
                            'rounded-lg px-3 py-1.5 text-sm',
                            'bg-primary-50 dark:bg-primary-500/10' => $mine && ! $deleted,
                            'bg-gray-50 dark:bg-white/5' => ! $mine || $deleted,
                            'ring-2 ring-warning-400' => $messageId === $message->id,
                            'opacity-60' => $scheduled,
                        ])>
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                                <span>
                                    <span class="font-medium text-gray-700 dark:text-gray-200">{{ $message->author->fullName() }}</span>
                                    · {{ $message->created_at->isoFormat('D MMM, HH:mm') }}
                                    @if ($message->edited_at) · {{ $m('edited') }} @endif
                                    @if ($message->pinned_at) · 📌 @endif
                                    @if ($scheduled) · {{ $m('scheduled_for', ['at' => $message->send_at?->isoFormat('LLL')]) }} @endif
                                    @if ($mine && ! $deleted && ($status = $reader->deliveryStatus($chat, $message)))
                                        · <span data-test="delivery">{{ $m('delivery.'.$status) }}</span>
                                    @endif
                                </span>
                                @if ($count > 0)
                                    <button type="button" class="underline" wire:click="toggleThread({{ $message->id }})">
                                        {{ ($collapsed[$message->id] ?? false) ? $m('unfold', ['count' => $count]) : $m('fold') }}
                                    </button>
                                @endif
                            </div>

                            @if ($deleted)
                                <div class="italic text-gray-500">{{ $m('deleted') }}</div>
                            @else
                                @if ($message->quoted && ! $message->quoted->isDeleted())
                                    <blockquote class="my-1 border-s-2 border-gray-300 ps-2 text-xs text-gray-500">
                                        {{ $message->quoted->author->fullName() }}: {{ str($message->quoted->body ?? '')->limit(160) }}
                                    </blockquote>
                                @endif

                                @if ($message->isForward())
                                    <div class="my-1 rounded border border-gray-200 px-2 py-1 text-xs dark:border-white/10" data-test="forwarded">
                                        @if ($originals[$message->id] ?? false)
                                            <div class="text-gray-500">{{ $m('forwarded_from', ['name' => $message->forwardedFrom->author->fullName()]) }}</div>
                                            <div class="whitespace-pre-line text-sm">{{ $message->forwardedFrom->body }}</div>
                                            @include('filament.messaging.attachments', ['attachments' => $message->forwardedFrom->attachments])
                                        @else
                                            <span class="italic text-gray-500">{{ $m('forwarded_hidden') }}</span>
                                        @endif
                                    </div>
                                @endif

                                @if (filled($message->body))
                                    <div @class(['whitespace-pre-line', 'italic text-gray-600 dark:text-gray-300' => $message->kind === 'system'])>{{ $message->body }}@if ($message->mentions_all) <span class="text-xs text-warning-600">@all</span>@endif</div>
                                @endif
                                @if ($message->mentioned->isNotEmpty())
                                    <div class="text-xs text-primary-600">{{ $message->mentioned->map(fn ($p) => '@'.$p->fullName())->implode(' ') }}</div>
                                @endif
                                @if ($message->task_id)
                                    <a class="text-xs underline" href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $message->task_id]) }}">→ {{ $m('open_task') }}</a>
                                @endif

                                @include('filament.messaging.attachments', ['attachments' => $message->attachments])

                                @if ($message->kind === 'poll')
                                    <div class="mt-1 flex flex-col gap-1" data-test="chat-poll">
                                        @foreach ($message->pollOptions as $option)
                                            <button type="button" wire:click="vote({{ $message->id }}, {{ $option->id }})" @class([
                                                'flex items-center justify-between rounded border px-2 py-0.5 text-left text-xs',
                                                'border-primary-500 bg-white dark:bg-white/10' => ($decor['my_votes'][$message->id] ?? null) === $option->id,
                                                'border-gray-200 hover:bg-white dark:border-white/10' => ($decor['my_votes'][$message->id] ?? null) !== $option->id,
                                            ])><span>{{ $option->text }}</span><span class="text-gray-500">{{ $decor['votes'][$option->id] ?? 0 }}</span></button>
                                        @endforeach
                                    </div>
                                @endif

                                @if (! $scheduled && $message->kind !== 'system')
                                    <div class="mt-1 flex flex-wrap items-center gap-1">
                                        @foreach ($this->reactionTypes as $code => $reaction)
                                            @php($n = $decor['reactions'][$message->id][$code] ?? 0)
                                            @if ($n > 0 || $loop->first)
                                                <button type="button" title="{{ $reaction['name'] }}" wire:click="react({{ $message->id }}, '{{ $code }}')" @class([
                                                    $smallButton, 'bg-white dark:bg-white/10' => ($decor['mine'][$message->id] ?? null) === $code,
                                                ])>{{ $reaction['symbol'] }} {{ $n ?: '' }}</button>
                                            @endif
                                        @endforeach
                                        @if ($mayWrite)
                                            <button type="button" class="{{ $smallButton }}" wire:click="reply({{ $message->id }})">{{ $m('reply') }}</button>
                                            <button type="button" class="{{ $smallButton }}" wire:click="quoteMessage({{ $message->id }})">{{ $m('quote') }}</button>
                                        @endif
                                        <button type="button" class="{{ $smallButton }}" wire:click="mountAction('forward', { message: {{ $message->id }} })">{{ $m('forward') }}</button>
                                        @if ($mayPin)
                                            <button type="button" class="{{ $smallButton }}" wire:click="pin({{ $message->id }}, {{ $message->pinned_at ? 'false' : 'true' }})">{{ $message->pinned_at ? $m('unpin') : $m('pin') }}</button>
                                        @endif
                                        @if ($mayWrite && \App\Filament\Resources\Tasks\TaskResource::canCreate())
                                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('task', { message: {{ $message->id }} })">{{ $m('to_task') }}</button>
                                        @endif
                                        <a class="{{ $smallButton }}" href="{{ \App\Filament\Pages\Messenger::getUrl(['chat' => $chat->id, 'message' => $message->id]) }}#message-{{ $message->id }}" title="{{ $m('link_to_message') }}">🔗</a>
                                        @if ($message->parent_id)
                                            <a class="{{ $smallButton }}" href="#message-{{ $message->root_id }}" title="{{ $m('to_root') }}">↑</a>
                                        @endif
                                        @if ($mine && ! $message->isForward())
                                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('edit', { message: {{ $message->id }} })">{{ $m('edit') }}</button>
                                        @endif
                                        @if ($mine || $mayModerate)
                                            <button type="button" class="{{ $smallButton }} text-danger-600" wire:click="deleteMessage({{ $message->id }})" wire:confirm="{{ $m('delete_confirm') }}">{{ $m('delete') }}</button>
                                        @endif
                                    </div>
                                @elseif ($scheduled)
                                    <div class="mt-1">
                                        <button type="button" class="{{ $smallButton }} text-danger-600" wire:click="deleteMessage({{ $message->id }})">{{ $m('cancel_scheduled') }}</button>
                                    </div>
                                @endif
                            @endif
                        </article>
                    @empty
                        <p class="m-auto text-sm text-gray-500">{{ $m('no_messages') }}</p>
                    @endforelse
                </div>

                @if ($mayWrite)
                    <footer class="border-t border-gray-100 pt-2 dark:border-white/5" data-test="composer">
                        @if ($replyMessage)
                            <div class="mb-1 flex items-center justify-between gap-2 rounded bg-gray-50 px-2 py-1 text-xs text-gray-500 dark:bg-white/5">
                                <span class="truncate">{{ $quoteMessage ? $m('quoting') : $m('replying') }} {{ $replyMessage->author->fullName() }}: {{ str($replyMessage->body ?? '')->limit(80) }}</span>
                                <button type="button" wire:click="cancelReply">×</button>
                            </div>
                        @endif
                        <form wire:submit="send" class="flex items-end gap-2">
                            <textarea wire:model.blur="body" rows="2" class="w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"
                                      placeholder="{{ $m('write') }}" x-on:keydown.ctrl.enter="$wire.set('body', $event.target.value).then(() => $wire.send())"></textarea>
                            <div class="flex flex-col gap-1">
                                <x-filament::button type="submit" size="sm">{{ $m('send') }}</x-filament::button>
                                <x-filament::button type="button" size="xs" color="gray" icon="heroicon-o-paper-clip" wire:click="mountAction('compose')" title="{{ $m('compose') }}">{{ $m('more') }}</x-filament::button>
                            </div>
                        </form>
                    </footer>
                @endif
            @endif
        </section>
    </div>
</x-filament-panels::page>
