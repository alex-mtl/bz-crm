<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Events\Actions\ManageEvents;
use App\Domain\Events\CalendarExport;
use App\Domain\Events\EventVisibility;
use App\Domain\Events\Models\Event;
use App\Domain\Events\Models\EventAttachment;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Events outside the panel (ФО §6.7, ТЗ §22, §68): the .ics file of an event, the iCal feed an external calendar
 * reads by a secret address, the files of an event. The domain services decide who gets what.
 */
final class EventCalendarController
{
    public function ics(Request $request, Event $event, EventVisibility $visibility, CalendarExport $calendar): Response
    {
        $user = $request->user();
        // Not visible — not found: the existence of the event is not disclosed.
        abort_unless($user instanceof User && $visibility->canSee($user, $event), 404);

        return $this->calendar($calendar->ics([$event], $event->title), 'event-'.$event->id.'.ics', download: true);
    }

    /**
     * No session here: a calendar application cannot sign in. The token is the key; what it opens is decided at
     * every request by what the owner of the feed may see now.
     */
    public function feed(string $token, CalendarExport $calendar): Response
    {
        $feed = $calendar->feedByToken($token);
        abort_if($feed === null, 404);
        $feed->forceFill(['last_used_at' => now()])->saveQuietly();

        return $this->calendar($calendar->ics($calendar->feedEvents($feed)->get(), (string) config('app.name')), 'calendar.ics', download: false);
    }

    public function attachment(Request $request, EventAttachment $attachment, ManageEvents $events): BinaryFileResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $path = $events->attachmentPath($user, $attachment);

        return $attachment->kind === 'photo'
            ? response()->file($path, ['Content-Type' => (string) $attachment->mime, 'Cache-Control' => 'private, max-age=300'])
            : response()->download($path, $attachment->original_name);
    }

    private function calendar(string $body, string $name, bool $download): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
