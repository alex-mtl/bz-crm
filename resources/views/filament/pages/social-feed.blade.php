<x-filament-panels::page>
    @php
        $ui = fn (string $key, array $replace = []): string => __('social.ui.'.$key, $replace);
        $smallButton = 'rounded border border-gray-200 px-2 py-0.5 text-xs hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5';
    @endphp

    @if ($mutedUntil)
        <div class="rounded-lg bg-warning-50 px-4 py-2 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400" data-test="muted-banner">
            {{ __('social.errors.muted', ['until' => $mutedUntil->isoFormat('LLL')]) }}
        </div>
    @endif

    @if ($this->postId)
        <div>
            <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-left" wire:click="showAll">{{ $ui('back_to_feed') }}</x-filament::button>
        </div>
    @else
        <div class="flex flex-wrap items-center gap-2" data-test="feed-toolbar">
            @foreach ($this->modes as $code => $label)
                <button type="button" wire:click="$set('mode', '{{ $code }}')" @class([
                    'rounded-full px-3 py-1 text-sm',
                    'bg-primary-600 text-white' => $mode === $code,
                    'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-200' => $mode !== $code,
                ])>{{ $label }}</button>
            @endforeach
            <x-filament::input.wrapper class="w-64">
                <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ $ui('search') }}" />
            </x-filament::input.wrapper>
            @if ($this->myGroups !== [])
                <x-filament::input.wrapper class="w-56">
                    <x-filament::input.select wire:model.live="groupId">
                        <option value="">{{ $ui('all_groups') }}</option>
                        @foreach ($this->myGroups as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            @endif
            @if ($canCreate)
                <x-filament::button size="sm" icon="heroicon-o-pencil-square" wire:click="mountAction('compose')">{{ $ui('new_post') }}</x-filament::button>
            @endif
        </div>
    @endif

    <div class="flex max-w-3xl flex-col gap-4" data-test="feed">
        @forelse ($posts as $post)
            @php($card = $cards[$post->id])
            @php($rights = $may[$post->id])
            <article wire:key="post-{{ $post->id }}" data-test="post" @class([
                'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10',
                'opacity-70' => $post->isHidden() || $post->status !== 'published',
            ])>
                <header class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <div class="font-semibold">{{ $post->author->fullName() }}</div>
                        <div class="text-xs text-gray-500">
                            @if ($post->status === 'published')
                                {{ $post->published_at?->isoFormat('LLL') }}
                            @elseif ($post->status === 'scheduled')
                                {{ $ui('scheduled_for', ['at' => $post->publish_at?->isoFormat('LLL')]) }}
                            @else
                                {{ $ui('draft') }}
                            @endif
                            · {{ __('social.visibility.'.$post->visibility) }}@if ($card['audience'] !== []): {{ implode(', ', $card['audience']) }}@endif
                            @if ($post->edited_at) · {{ $ui('edited') }} @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        @foreach ($card['pins'] as $pin)
                            <span class="rounded-full bg-primary-50 px-2 py-0.5 text-xs text-primary-700 dark:bg-primary-500/10 dark:text-primary-400" data-test="pin">
                                📌 {{ $ui('pin_scopes.'.$pin->scope) }}
                                @if ($rights['pin'])
                                    <button type="button" wire:click="unpin({{ $pin->id }})" title="{{ $ui('unpin') }}">×</button>
                                @endif
                            </span>
                        @endforeach
                        @if (! $rights['own'])
                            <button type="button" class="{{ $smallButton }}" wire:click="toggleFollow({{ $post->author_person_id }})">
                                {{ $card['following'] ? $ui('unfollow') : $ui('follow') }}
                            </button>
                        @endif
                    </div>
                </header>

                @if ($post->isHidden())
                    <p class="mt-2 rounded bg-danger-50 px-3 py-1 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-400" data-test="hidden-note">
                        {{ $ui('hidden_by_moderator') }}: {{ $post->hidden_reason }}
                    </p>
                @endif

                @if ($post->body)
                    <div class="mt-3 whitespace-pre-line text-sm">{{ $post->body }}</div>
                @endif

                @if ($post->repost_of_post_id)
                    <div class="mt-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10" data-test="repost">
                        @if ($card['original'])
                            <div class="text-xs text-gray-500">{{ $card['original']->author->fullName() }} · {{ $card['original']->published_at?->isoFormat('LL') }}</div>
                            <div class="mt-1 whitespace-pre-line">{{ $card['original']->body }}</div>
                        @else
                            <span class="text-gray-500">{{ $ui('original_unavailable') }}</span>
                        @endif
                    </div>
                @endif

                @if ($post->attachments->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($post->attachments as $attachment)
                            @if ($attachment->kind === 'image')
                                <a href="{{ route('social.attachment', $attachment) }}" target="_blank">
                                    <img src="{{ route('social.attachment', $attachment) }}" alt="{{ $attachment->original_name }}" class="max-h-48 rounded-lg" loading="lazy" />
                                </a>
                            @else
                                <a href="{{ route('social.attachment', $attachment) }}" class="{{ $smallButton }}">📎 {{ $attachment->original_name }}</a>
                            @endif
                        @endforeach
                    </div>
                @endif

                @if ($card['poll'])
                    <div class="mt-3 flex flex-col gap-1" data-test="poll">
                        @foreach ($card['poll']['options'] as $option)
                            <button type="button" wire:click="vote({{ $post->id }}, {{ $option['id'] }})" @class([
                                'flex items-center justify-between rounded-lg border px-3 py-1 text-left text-sm',
                                'border-primary-500 bg-primary-50 dark:bg-primary-500/10' => $card['poll']['my_option'] === $option['id'],
                                'border-gray-200 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5' => $card['poll']['my_option'] !== $option['id'],
                            ])>
                                <span>{{ $option['text'] }}</span>
                                <span class="text-xs text-gray-500">{{ $option['votes'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                @if ($post->status === 'published' && ! $post->isHidden())
                    <footer class="mt-3 flex flex-wrap items-center gap-1 border-t border-gray-100 pt-2 dark:border-white/5">
                        @foreach ($this->reactionTypes as $code => $reaction)
                            <button type="button" wire:click="react('post', {{ $post->id }}, '{{ $code }}')" title="{{ $reaction['name'] }}" @class([
                                $smallButton, 'bg-primary-50 dark:bg-primary-500/10' => $card['my_reaction'] === $code,
                            ])>{{ $reaction['symbol'] }} {{ $card['reactions'][$code] ?? '' }}</button>
                        @endforeach
                        <button type="button" class="{{ $smallButton }}" wire:click="toggleComments({{ $post->id }})" data-test="comments-toggle">
                            💬 {{ $card['comments'] }}
                        </button>
                        @if ($canCreate)
                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('compose', { repost: {{ $post->id }} })">{{ $ui('repost') }}</button>
                        @endif
                        @if (! $rights['own'])
                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('report', { type: 'post', id: {{ $post->id }} })">{{ $ui('report') }}</button>
                        @endif
                        @if ($rights['pin'])
                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('pin', { post: {{ $post->id }} })">{{ $ui('pin') }}</button>
                        @endif
                    </footer>
                @endif

                @if ($rights['own'] || $rights['moderate'] || $rights['revisions'])
                    <div class="mt-2 flex flex-wrap items-center gap-1" data-test="post-manage">
                        @if ($rights['own'])
                            @if ($post->status !== 'published')
                                <button type="button" class="{{ $smallButton }}" wire:click="publishNow({{ $post->id }})">{{ $ui('publish_now') }}</button>
                            @endif
                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('edit', { post: {{ $post->id }} })">{{ $ui('edit') }}</button>
                            <button type="button" class="{{ $smallButton }}" wire:click="deletePost({{ $post->id }})" wire:confirm="{{ $ui('delete_confirm') }}">{{ $ui('delete') }}</button>
                        @endif
                        @if ($rights['own'] || $rights['moderate'])
                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('audience', { post: {{ $post->id }} })">{{ $ui('change_audience') }}</button>
                        @endif
                        @if ($rights['revisions'])
                            <button type="button" class="{{ $smallButton }}" wire:click="mountAction('revisions', { post: {{ $post->id }} })">{{ $ui('revisions') }}</button>
                        @endif
                        @if ($rights['moderate'])
                            @if ($post->isHidden())
                                <button type="button" class="{{ $smallButton }}" wire:click="restore('post', {{ $post->id }})">{{ $ui('restore') }}</button>
                            @else
                                <button type="button" class="{{ $smallButton }} text-danger-600" wire:click="mountAction('hide', { type: 'post', id: {{ $post->id }} })">{{ $ui('hide') }}</button>
                            @endif
                        @endif
                    </div>
                @endif

                @if ($open[$post->id] ?? false)
                    <section class="mt-3 flex flex-col gap-2" data-test="comments">
                        @foreach ($threads[$post->id] ?? [] as $comment)
                            @php($gone = $comment->deleted_at !== null)
                            @php($mayHide = $rights['own'] || $rights['moderate'])
                            <div wire:key="comment-{{ $comment->id }}" class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5"
                                 style="margin-inline-start: {{ min($comment->depth, 6) * 1.25 }}rem">
                                <div class="mb-1 text-xs text-gray-500">{{ $comment->author->fullName() }} · {{ $comment->created_at->isoFormat('LLL') }}</div>
                                @if ($gone)
                                    <div class="italic text-gray-500">{{ $ui('comment_deleted') }}</div>
                                @elseif ($comment->isHidden() && ! $mayHide && $comment->author_person_id !== $actorPersonId)
                                    <div class="italic text-gray-500">{{ $ui('comment_hidden') }}</div>
                                @else
                                    @if ($comment->isHidden())
                                        <div class="mb-1 text-xs text-danger-600">{{ $ui('hidden_by_moderator') }}: {{ $comment->hidden_reason }}</div>
                                    @endif
                                    @if ($comment->quoted && ! $comment->quoted->deleted_at && ! $comment->quoted->isHidden())
                                        <blockquote class="mb-1 border-s-2 border-gray-300 ps-2 text-xs text-gray-500">
                                            {{ $comment->quoted->author->fullName() }}: {{ str($comment->quoted->body)->limit(160) }}
                                        </blockquote>
                                    @endif
                                    <div class="whitespace-pre-line">{{ $comment->body }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1">
                                        @if (! $comment->isHidden())
                                            @foreach ($this->reactionTypes as $code => $reaction)
                                                @php($count = $commentReactions[$comment->id]['reactions'][$code] ?? 0)
                                                @if ($count > 0 || $loop->first)
                                                    <button type="button" wire:click="react('comment', {{ $comment->id }}, '{{ $code }}')" title="{{ $reaction['name'] }}" @class([
                                                        $smallButton, 'bg-primary-50 dark:bg-primary-500/10' => ($commentReactions[$comment->id]['my_reaction'] ?? null) === $code,
                                                    ])>{{ $reaction['symbol'] }} {{ $count ?: '' }}</button>
                                                @endif
                                            @endforeach
                                            @if ($canComment && ! $mutedUntil)
                                                <button type="button" class="{{ $smallButton }}" wire:click="mountAction('comment', { post: {{ $post->id }}, parent: {{ $comment->id }} })">{{ $ui('reply') }}</button>
                                                <button type="button" class="{{ $smallButton }}" wire:click="mountAction('comment', { post: {{ $post->id }}, parent: {{ $comment->id }}, quoted: {{ $comment->id }} })">{{ $ui('quote') }}</button>
                                            @endif
                                            @if ($comment->author_person_id === $actorPersonId)
                                                <button type="button" class="{{ $smallButton }}" wire:click="deleteComment({{ $comment->id }})" wire:confirm="{{ $ui('delete_confirm') }}">{{ $ui('delete') }}</button>
                                            @else
                                                <button type="button" class="{{ $smallButton }}" wire:click="mountAction('report', { type: 'comment', id: {{ $comment->id }} })">{{ $ui('report') }}</button>
                                            @endif
                                            @if ($mayHide && $comment->author_person_id !== $actorPersonId)
                                                <button type="button" class="{{ $smallButton }} text-danger-600" wire:click="mountAction('hide', { type: 'comment', id: {{ $comment->id }} })">{{ $ui('hide') }}</button>
                                            @endif
                                        @elseif ($rights['moderate'])
                                            <button type="button" class="{{ $smallButton }}" wire:click="restore('comment', {{ $comment->id }})">{{ $ui('restore') }}</button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        @if ($canComment && ! $mutedUntil && $post->status === 'published' && ! $post->isHidden())
                            <div>
                                <x-filament::button size="xs" color="gray" wire:click="mountAction('comment', { post: {{ $post->id }} })">{{ $ui('comment') }}</x-filament::button>
                            </div>
                        @endif
                    </section>
                @endif
            </article>
        @empty
            <p class="text-sm text-gray-500" data-test="feed-empty">{{ $ui('empty') }}</p>
        @endforelse

        @if ($hasMore)
            <div>
                <x-filament::button color="gray" size="sm" wire:click="more">{{ $ui('more') }}</x-filament::button>
            </div>
        @endif
    </div>
</x-filament-panels::page>
