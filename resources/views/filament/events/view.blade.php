<x-filament-panels::page>
    @php
        $e = fn (string $key, array $replace = []): string => __('events.ui.'.$key, $replace);
        $smallButton = 'rounded border border-gray-200 px-2 py-0.5 text-xs hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5';
        $box = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10';
    @endphp

    @if ($event->isCancelled())
        <div class="rounded-lg bg-danger-50 px-4 py-2 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-400" data-test="event-cancelled">
            {{ $e('cancelled') }}: {{ $event->cancel_reason }}
        </div>
    @endif

    <section class="{{ $box }}" data-test="event-about">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <x-filament::badge color="info">{{ $type }}</x-filament::badge>
            <x-filament::badge color="gray">
                {{ __('events.visibility.'.$event->visibility) }}@if ($audience !== []): {{ implode(', ', $audience) }}@endif
            </x-filament::badge>
            @if ($event->isRecurring())
                <x-filament::badge color="gray">{{ $e('recurring') }}</x-filament::badge>
            @endif
            @if ($mine?->rsvp)
                <x-filament::badge :color="match ($mine->rsvp) { 'going' => 'success', 'interested' => 'info', default => 'gray' }" data-test="my-answer">
                    {{ $e('my_answer') }}: {{ __('events.rsvp.'.$mine->rsvp) }}
                </x-filament::badge>
            @elseif ($mine?->invited_at)
                <x-filament::badge color="warning" data-test="my-answer">{{ $e('invited_no_answer') }}</x-filament::badge>
            @endif
        </div>
        <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
            <div><dt class="inline text-gray-500">{{ $e('when') }}:</dt>
                <dd class="inline">{{ $event->starts_at->isoFormat('LLLL') }} — {{ $event->ends_at->isSameDay($event->starts_at) ? $event->ends_at->format('H:i') : $event->ends_at->isoFormat('LLL') }}</dd></div>
            <div><dt class="inline text-gray-500">{{ $e('organizer') }}:</dt> <dd class="inline">{{ $event->organizer->fullName() }}</dd></div>
            @if ($event->location)
                <div><dt class="inline text-gray-500">{{ $e('location') }}:</dt> <dd class="inline">{{ $event->location }}</dd></div>
            @endif
            @if ($event->latitude !== null)
                <div><dt class="inline text-gray-500">{{ $e('point') }}:</dt>
                    <dd class="inline"><a class="underline" target="_blank" rel="noopener"
                        href="https://www.openstreetmap.org/?mlat={{ $event->latitude }}&mlon={{ $event->longitude }}#map=17/{{ $event->latitude }}/{{ $event->longitude }}">{{ $event->latitude }}, {{ $event->longitude }}</a></dd></div>
            @endif
            @if ($event->reminder_minutes)
                <div><dt class="inline text-gray-500">{{ $e('remind') }}:</dt>
                    <dd class="inline">{{ collect($event->reminder_minutes)->map(fn ($m) => \App\Filament\Resources\Events\EventResource::reminderLabel((int) $m))->implode(', ') }}</dd></div>
            @endif
        </dl>
        @if ($event->description)
            <p class="mt-3 whitespace-pre-line text-sm">{{ $event->description }}</p>
        @endif
        <div class="mt-3 flex flex-wrap gap-2">
            <x-filament::button tag="a" size="xs" color="gray" icon="heroicon-o-calendar" href="{{ $googleUrl }}" target="_blank" rel="noopener">{{ $e('add_to_google') }}</x-filament::button>
            <x-filament::button tag="a" size="xs" color="gray" icon="heroicon-o-arrow-down-tray" href="{{ route('events.ics', $event) }}">{{ $e('download_ics') }}</x-filament::button>
        </div>
        @if ($series->isNotEmpty())
            <div class="mt-3 text-sm text-gray-500">
                {{ $e('next_occurrences') }}:
                @foreach ($series as $next)
                    <a class="underline" href="{{ \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $next]) }}">{{ $next->starts_at->isoFormat('D MMM') }}</a>@if (! $loop->last), @endif
                @endforeach
            </div>
        @endif
    </section>

    @if ($event->results_published_at || $event->attachments->isNotEmpty())
        <section class="{{ $box }}" data-test="event-results">
            <h3 class="mb-2 text-sm font-semibold">{{ $e('results') }}</h3>
            @if ($event->results)
                <p class="whitespace-pre-line text-sm">{{ $event->results }}</p>
            @endif
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($event->attachments as $attachment)
                    @if ($attachment->kind === 'photo')
                        <a href="{{ route('events.attachment', $attachment) }}" target="_blank">
                            <img src="{{ route('events.attachment', $attachment) }}" alt="{{ $attachment->original_name }}" class="max-h-40 rounded-lg" loading="lazy" />
                        </a>
                    @else
                        <a href="{{ route('events.attachment', $attachment) }}" class="{{ $smallButton }}">📎 {{ $attachment->original_name }}</a>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

    <section class="{{ $box }}" data-test="event-attendees">
        <h3 class="mb-2 text-sm font-semibold">
            {{ $e('people') }} —
            <span class="font-normal text-gray-500">
                {{ __('events.rsvp.going') }}: {{ $counts['going'] }} · {{ __('events.rsvp.interested') }}: {{ $counts['interested'] }}
                @if ($runs) · {{ __('events.rsvp.declined') }}: {{ $counts['declined'] }} @endif
                @if ($event->hasStarted()) · {{ $e('attended') }}: {{ $counts['attended'] }} @endif
            </span>
        </h3>
        @forelse ($attendees as $attendee)
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 py-2 text-sm dark:border-white/5" wire:key="attendee-{{ $attendee->id }}">
                <div>
                    <span class="font-medium">{{ $attendee->person->fullName() }}</span>
                    <span class="text-gray-500">· {{ $attendee->rsvp ? __('events.rsvp.'.$attendee->rsvp) : $e('invited_no_answer') }}</span>
                    @if ($runs && $attendee->rsvp_comment)
                        <span class="text-gray-500">— {{ $attendee->rsvp_comment }}</span>
                    @endif
                    @if ($attendee->attended)
                        <x-filament::badge color="success" class="ms-1 inline-flex">{{ $e('attended') }}</x-filament::badge>
                    @endif
                </div>
                <div class="flex flex-wrap gap-1">
                    @if ($mayMark)
                        <button type="button" class="{{ $smallButton }}" wire:click="markAttendance({{ $attendee->person_id }}, {{ $attendee->attended ? 'false' : 'true' }})">
                            {{ $attendee->attended ? $e('unmark') : $e('mark_attended') }}
                        </button>
                    @endif
                    @if ($mayInvite && ! $attendee->attended && ! $event->isCancelled())
                        <button type="button" class="{{ $smallButton }} text-danger-600" wire:click="uninvite({{ $attendee->person_id }})"
                                wire:confirm="{{ $e('uninvite_confirm') }}">{{ $e('uninvite') }}</button>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ $e('nobody_yet') }}</p>
        @endforelse
    </section>
</x-filament-panels::page>
