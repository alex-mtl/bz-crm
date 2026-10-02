<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\Events\Models\Event;
use App\Domain\Geo\Models\GeoZone;
use App\Domain\Geo\Models\GeoZoneLink;
use App\Domain\Geo\Notifications\FieldNotice;
use App\Domain\People\Models\Person;

/**
 * What is bound to a geozone (ФО §6.11: «мероприятие в геозоне X уведомляет ответственных за X»). An event
 * with a point on the map is bound by itself to every zone the point stands in; a person may bind one by hand.
 * A new binding of a coming event tells the people who answer for the zone — those of them who may see the event.
 */
final readonly class ZoneBindings
{
    public function __construct(private GeoService $geo) {}

    /**
     * Called when an event is created or moved.
     */
    public function eventChanged(Event $event): void
    {
        $inside = $event->latitude !== null && $event->longitude !== null && ! $event->isCancelled()
            ? $this->geo->zonesAt((float) $event->latitude, (float) $event->longitude)->keyBy('id')
            : collect();

        GeoZoneLink::query()->where('subject_type', GeoZoneLink::EVENT)->where('subject_id', $event->id)
            ->where('origin', GeoZoneLink::AUTO)->whereNotIn('geo_zone_id', $inside->keys())->delete();

        foreach ($inside as $zone) {
            $this->bind($zone, $event, GeoZoneLink::AUTO);
        }
    }

    /**
     * Called when the outline of a zone is drawn or redrawn: the coming events are bound anew.
     */
    public function zoneChanged(GeoZone $zone): void
    {
        $events = Event::query()->whereNull('cancelled_at')->where('ends_at', '>=', now())->whereNotNull('latitude')
            ->whereBetween('latitude', [$zone->min_latitude, $zone->max_latitude])
            ->whereBetween('longitude', [$zone->min_longitude, $zone->max_longitude])
            ->orderBy('starts_at')->limit(500)->get();

        $inside = $events->filter(fn (Event $event): bool => $this->geo->zoneContains($zone, (float) $event->latitude, (float) $event->longitude));
        GeoZoneLink::query()->where('geo_zone_id', $zone->id)->where('subject_type', GeoZoneLink::EVENT)
            ->where('origin', GeoZoneLink::AUTO)->whereNotIn('subject_id', $inside->pluck('id'))
            ->whereIn('subject_id', Event::query()->where('ends_at', '>=', now())->select('id'))->delete();

        foreach ($inside as $event) {
            $this->bind($zone, $event, GeoZoneLink::AUTO);
        }
    }

    public function bind(GeoZone $zone, Event $event, string $origin, ?int $byPersonId = null): void
    {
        $link = GeoZoneLink::query()->firstOrCreate(
            ['geo_zone_id' => $zone->id, 'subject_type' => GeoZoneLink::EVENT, 'subject_id' => $event->id],
            ['origin' => $origin, 'linked_by_person_id' => $byPersonId],
        );
        if (! $link->wasRecentlyCreated || ! $zone->notify_events || $zone->isArchived() || $event->ends_at->isPast()) {
            return;
        }

        $zone->responsibles()->with('user')->get()
            ->filter(fn (Person $person): bool => $person->user !== null && $person->id !== $event->organizer_person_id)
            ->each(fn (Person $person) => $person->user->notify(new FieldNotice(FieldNotice::ZONE_EVENT, [
                'zone' => $zone->name, 'title' => $event->title, 'event_id' => $event->id,
                'body' => $event->starts_at->isoFormat('LLL'), 'url' => '/admin/events/'.$event->id,
            ])));
    }

    /**
     * The events bound to a zone, newest first.
     *
     * @return list<int>
     */
    public function eventIds(GeoZone $zone): array
    {
        return GeoZoneLink::query()->where('geo_zone_id', $zone->id)->where('subject_type', GeoZoneLink::EVENT)
            ->pluck('subject_id')->map(fn ($id): int => (int) $id)->all();
    }
}
