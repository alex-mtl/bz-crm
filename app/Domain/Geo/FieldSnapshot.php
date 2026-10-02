<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Geo\Models\Apartment;
use App\Domain\Geo\Models\ApartmentNote;
use App\Domain\Geo\Models\House;
use App\Domain\Identity\Models\User;

/**
 * What the agitator's phone keeps to work at the entrance without a network (ТЗ §33–34): the houses they answer
 * for with every flat, the statuses to choose from, their own notes. Nothing else of the database goes offline.
 */
final readonly class FieldSnapshot
{
    public function __construct(
        private FieldAccess $access,
        private FieldSettings $settings,
    ) {}

    /**
     * @return array{user: array{id: int, name: string}, server_time: string, default_note_visibility: string, statuses: list<array<string, mixed>>, houses: list<array<string, mixed>>}
     */
    public function for(User $user): array
    {
        $houses = $this->access->assignedHouses($user->person_id)
            ->whereIn('houses.id', $this->access->houses($user, 'geo.visits.create')->select('houses.id'))
            ->with(['address.street', 'territory', 'apartments'])->orderBy('houses.id')->get();

        $notes = ApartmentNote::query()->whereIn('house_id', $houses->pluck('id'))->where('author_person_id', $user->person_id)
            ->orderBy('id')->get()->groupBy('apartment_id');

        return [
            'user' => ['id' => $user->id, 'name' => $user->person->fullName()],
            'server_time' => now()->toIso8601String(),
            'default_note_visibility' => $this->settings->defaultNoteVisibility(),
            'statuses' => CatalogItem::query()->ofCatalog('canvass_statuses')->selectable()->get()
                ->map(fn (CatalogItem $status): array => [
                    'code' => $status->code, 'name' => $status->name(), 'color' => (string) $status->property('color', 'gray'),
                    'visited' => (bool) $status->property('visited'), 'supporter' => (bool) $status->property('supporter'),
                    'retry' => (bool) $status->property('retry'),
                ])->values()->all(),
            'houses' => $houses->map(fn (House $house): array => [
                'id' => $house->id,
                'label' => $house->label(),
                'territory' => $house->territory->name(),
                'type' => $house->type_code,
                'entrances' => $house->entrances,
                'apartments' => $house->apartments->map(fn (Apartment $apartment): array => [
                    'id' => $apartment->id,
                    'number' => $apartment->number,
                    'entrance' => $apartment->entrance,
                    'floor' => $apartment->floor,
                    'status' => $apartment->status_code,
                    'attempts' => $apartment->attempts,
                    'last_visit_at' => $apartment->last_visit_at?->toIso8601String(),
                    'next_visit_on' => $apartment->next_visit_on?->toDateString(),
                    // Only the agitator's own notes travel to the phone.
                    'notes' => ($notes[$apartment->id] ?? collect())->map(fn (ApartmentNote $note): array => [
                        'body' => $note->body, 'visibility' => $note->visibility, 'at' => $note->created_at->toIso8601String(),
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
