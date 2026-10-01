<?php

declare(strict_types=1);

namespace App\Domain\Events;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Events\Exceptions\EventRuleViolation;
use App\Domain\Events\Models\CalendarFeed;
use App\Domain\Events\Models\Event;
use App\Domain\Events\Models\EventAttendee;
use App\Domain\Geo\Models\Territory;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Events in external calendars (ФО §6.7, ТЗ §22): a Google Calendar link, an .ics file, an iCal feed.
 * One-way export only — nothing is read back from the external calendar.
 */
final readonly class CalendarExport
{
    public function __construct(
        private AuthorizationService $authorization,
        private EventVisibility $visibility,
        private GroupAccess $groups,
        private EventJournal $journal,
    ) {}

    /**
     * "Add to Google Calendar": a link with the title, the time, the place and the description filled in.
     */
    public function googleUrl(Event $event): string
    {
        $format = fn (CarbonInterface $moment): string => $moment->copy()->utc()->format('Ymd\THis\Z');

        return 'https://calendar.google.com/calendar/render?'.http_build_query(array_filter([
            'action' => 'TEMPLATE',
            'text' => $event->title,
            'dates' => $format($event->starts_at).'/'.$format($event->ends_at),
            'details' => $event->description,
            'location' => $event->location,
        ]));
    }

    /**
     * @param  iterable<Event>  $events
     */
    public function ics(iterable $events, string $calendarName): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//BZ-CRM-Social//Events//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:'.$this->escape($calendarName)];
        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        $format = fn (CarbonInterface $moment): string => $moment->copy()->utc()->format('Ymd\THis\Z');

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:event-'.$event->id.'@'.$host;
            $lines[] = 'DTSTAMP:'.$format($event->updated_at ?? $event->created_at);
            $lines[] = 'DTSTART:'.$format($event->starts_at);
            $lines[] = 'DTEND:'.$format($event->ends_at);
            $lines[] = 'SUMMARY:'.$this->escape($event->title);
            if (filled($event->description)) {
                $lines[] = 'DESCRIPTION:'.$this->escape((string) $event->description);
            }
            if (filled($event->location)) {
                $lines[] = 'LOCATION:'.$this->escape((string) $event->location);
            }
            if ($event->latitude !== null && $event->longitude !== null) {
                $lines[] = 'GEO:'.$event->latitude.';'.$event->longitude;
            }
            $lines[] = 'STATUS:'.($event->isCancelled() ? 'CANCELLED' : 'CONFIRMED');
            $lines[] = 'URL:'.url('/admin/events/'.$event->id);
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /**
     * A new subscription. The token is returned once; only its hash is stored.
     *
     * @return array{feed: CalendarFeed, token: string}
     */
    public function createFeed(User $user, string $scope, ?int $scopeId = null): array
    {
        $this->authorization->authorize($user, 'events.calendar.subscribe');
        $allowed = match ($scope) {
            CalendarFeed::PERSONAL => true,
            CalendarFeed::TERRITORY => $scopeId !== null && Territory::query()->whereKey($scopeId)->exists(),
            CalendarFeed::GROUP => $scopeId !== null && ($group = Group::query()->find($scopeId)) !== null && $this->groups->canSee($user, $group),
            default => throw EventRuleViolation::because('invalid_feed'),
        };
        if (! $allowed) {
            throw new AuthorizationException(__('access.denied'));
        }
        $token = Str::random(48);

        $feed = DB::transaction(function () use ($user, $scope, $scopeId, $token): CalendarFeed {
            $feed = CalendarFeed::query()->create([
                'user_id' => $user->id, 'token_hash' => CalendarFeed::hashToken($token),
                'scope' => $scope, 'scope_id' => $scope === CalendarFeed::PERSONAL ? null : $scopeId,
            ]);
            $this->journal->record('events.feed.created', $feed, [], ['scope' => $scope, 'scope_id' => $feed->scope_id]);

            return $feed;
        });

        return ['feed' => $feed, 'token' => $token];
    }

    public function revokeFeed(User $user, CalendarFeed $feed): void
    {
        if ($feed->user_id !== $user->id) {
            throw new AuthorizationException(__('access.denied'));
        }
        if ($feed->revoked_at !== null) {
            return;
        }

        DB::transaction(function () use ($feed): void {
            $feed->update(['revoked_at' => now()]);
            $this->journal->record('events.feed.revoked', $feed);
        });
    }

    /**
     * The feed behind a token, or null: unknown, revoked, or its owner can no longer sign in.
     */
    public function feedByToken(string $token): ?CalendarFeed
    {
        $feed = CalendarFeed::query()->with('user')->where('token_hash', CalendarFeed::hashToken($token))->whereNull('revoked_at')->first();

        return $feed !== null && $feed->user->isActive() ? $feed : null;
    }

    /**
     * What the feed shows now: the events its owner may see at this moment, narrowed by the scope of the feed.
     *
     * @return Builder<Event>
     */
    public function feedEvents(CalendarFeed $feed): Builder
    {
        return $this->scoped($feed->user, $feed->scope, $feed->scope_id)
            ->whereBetween('events.starts_at', [now()->subDays(30), now()->addDays(180)])
            ->orderBy('events.starts_at');
    }

    /**
     * Visible events of one calendar: the person's own, of a territory (with everything inside it), of a group.
     *
     * @return Builder<Event>
     */
    public function scoped(User $user, string $scope, ?int $scopeId = null): Builder
    {
        $query = $this->visibility->visibleTo($user);

        return match ($scope) {
            // "My" calendar: what I organize and what I was invited to or answered — unless I declined.
            CalendarFeed::PERSONAL => $query->where(fn (Builder $mine) => $mine
                ->where('events.organizer_person_id', $user->person_id)
                ->orWhereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('event_attendees as cal_a')
                    ->whereColumn('cal_a.event_id', 'events.id')->where('cal_a.person_id', $user->person_id)
                    ->where(fn (QueryBuilder $w) => $w->whereNull('cal_a.rsvp')->orWhere('cal_a.rsvp', '!=', EventAttendee::DECLINED)))),
            CalendarFeed::TERRITORY => $query->whereExists(function (QueryBuilder $sub) use ($scopeId): void {
                $path = (string) Territory::query()->whereKey($scopeId)->value('path');
                $sub->selectRaw('1')->from('event_territories as cal_t')->join('territories as cal_tt', 'cal_tt.id', '=', 'cal_t.territory_id')
                    ->whereColumn('cal_t.event_id', 'events.id')->where('cal_tt.path', 'like', ($path !== '' ? $path : '/none/').'%');
            }),
            CalendarFeed::GROUP => $query->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('event_groups as cal_g')
                ->whereColumn('cal_g.event_id', 'events.id')->where('cal_g.group_id', $scopeId)),
            default => $query,
        };
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /**
     * RFC 5545: lines longer than 75 octets are folded.
     */
    private function fold(string $line): string
    {
        $out = '';
        while (strlen($line) > 73) {
            $cut = mb_strcut($line, 0, 73, 'UTF-8');
            $out .= $cut."\r\n ";
            $line = substr($line, strlen($cut));
        }

        return $out.$line;
    }
}
