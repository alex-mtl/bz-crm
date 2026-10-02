<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeoZones\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Events\EventVisibility;
use App\Domain\Events\Models\Event;
use App\Domain\Geo\Actions\ManageVehicles;
use App\Domain\Geo\Actions\ManageZones;
use App\Domain\Geo\CanvassSummary;
use App\Domain\Geo\FieldSettings;
use App\Domain\Geo\GeoService;
use App\Domain\Geo\Models\GeoZone;
use App\Domain\Geo\Models\GeoZoneCrossing;
use App\Domain\Geo\Models\LocationShare;
use App\Domain\Geo\Models\Vehicle;
use App\Domain\Geo\ZoneBindings;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Filament\Resources\GeoZones\GeoZoneResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * The page of a geozone (ФО §6.11): the outline on the map — drawn by those who manage the zone — the people
 * who answer for it, the events bound to it, the canvass inside it, who entered and left.
 */
class ViewGeoZone extends ViewRecord
{
    protected static string $resource = GeoZoneResource::class;

    protected string $view = 'filament.geo-zones.view';

    #[Url]
    public ?string $edit = null;

    private function zone(): GeoZone
    {
        $record = $this->getRecord();
        assert($record instanceof GeoZone);

        return $record;
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function mayManage(): bool
    {
        return app(AuthorizationService::class)->can($this->actor(), 'geo.zones.manage', $this->zone());
    }

    public function getTitle(): string
    {
        return $this->zone()->name;
    }

    private function attempt(callable $action, ?string $success = null): bool
    {
        try {
            $action();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();

            return false;
        }
        $this->getRecord()->refresh();
        Notification::make()->title($success ?? __('admin.saved'))->success()->send();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $zone = $this->zone()->loadMissing(['territory', 'responsibles']);
        $actor = $this->actor();
        $authorization = app(AuthorizationService::class);
        $mayManage = $this->mayManage();

        return [
            'zone' => $zone,
            'mayManage' => $mayManage,
            'editing' => $mayManage && $this->edit === '1',
            'map' => [
                'tiles' => app(FieldSettings::class)->map(),
                'zone' => [
                    'corners' => app(GeoService::class)->zoneCorners($zone), 'color' => $zone->color,
                    'editable' => $mayManage && ! $zone->isArchived(), 'editing' => $mayManage && $this->edit === '1',
                ],
            ],
            // Only the bound events the reader may see anyway.
            'events' => app(EventVisibility::class)->visibleTo($actor)->whereKey(app(ZoneBindings::class)->eventIds($zone))
                ->orderByDesc('starts_at')->limit(30)->get(),
            'figures' => $authorization->can($actor, 'geo.summary.read') ? app(CanvassSummary::class)->forZone($actor, $zone) : null,
            'crossings' => $this->crossings($actor, $zone),
        ];
    }

    /**
     * Who entered and left — only the movers whose location the reader may see.
     *
     * @return list<array{crossing: GeoZoneCrossing, name: string}>
     */
    private function crossings(User $actor, GeoZone $zone): array
    {
        $authorization = app(AuthorizationService::class);
        $people = $authorization->scopeQuery($actor, 'geo.locations.read', LocationShare::query())->distinct()->pluck('person_id')
            ->push($actor->person_id)->unique()->all();
        $vehicles = app(ManageVehicles::class)->visibleTo($actor)->pluck('id')->all();

        $rows = GeoZoneCrossing::query()->where('geo_zone_id', $zone->id)
            ->where(fn (Builder $where) => $where
                ->where(fn (Builder $person) => $person->where('mover_type', GeoZoneCrossing::PERSON)->whereIn('mover_id', $people))
                ->orWhere(fn (Builder $vehicle) => $vehicle->where('mover_type', GeoZoneCrossing::VEHICLE)->whereIn('mover_id', $vehicles)))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(30)->get();
        $names = [
            GeoZoneCrossing::PERSON => Person::query()->whereKey($rows->where('mover_type', GeoZoneCrossing::PERSON)->pluck('mover_id'))->get()
                ->mapWithKeys(fn (Person $person): array => [$person->id => $person->fullName()]),
            GeoZoneCrossing::VEHICLE => Vehicle::query()->whereKey($rows->where('mover_type', GeoZoneCrossing::VEHICLE)->pluck('mover_id'))->pluck('name', 'id'),
        ];

        return $rows->map(fn (GeoZoneCrossing $crossing): array => [
            'crossing' => $crossing, 'name' => (string) ($names[$crossing->mover_type][$crossing->mover_id] ?? '—'),
        ])->all();
    }

    /**
     * Called by the map when the outline was reshaped and "save" pressed.
     *
     * @param  array<array-key, mixed>  $corners
     */
    public function saveOutline(array $corners): void
    {
        if ($this->attempt(fn () => app(ManageZones::class)->update($this->actor(), $this->zone(), ['corners' => $corners]), __('geo.ui.outline_saved'))) {
            $this->edit = null;
        }
    }

    protected function getHeaderActions(): array
    {
        $g = fn (string $key): string => __('geo.ui.'.$key);
        $zones = app(ManageZones::class);

        return [
            Action::make('edit')->label($g('edit'))->icon('heroicon-o-pencil-square')->color('gray')
                ->visible(fn (): bool => $this->mayManage())
                ->fillForm(fn (): array => [
                    ...$this->zone()->only(['name', 'color', 'description', 'notify_events', 'notify_crossings']),
                    'responsible_ids' => $this->zone()->responsibles()->pluck('people.id')->all(),
                ])
                ->schema(GeoZoneResource::fields(false))
                ->action(fn (array $data) => $this->attempt(fn () => $zones->update($this->actor(), $this->zone(), $data))),
            Action::make('linkEvent')->label($g('link_event'))->icon('heroicon-o-calendar-days')->color('gray')
                ->visible(fn (): bool => $this->mayManage())
                ->schema([
                    Select::make('event_id')->label($g('event'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => app(EventVisibility::class)->visibleTo($this->actor())
                            ->where('events.title', 'like', "%{$search}%")->orderByDesc('starts_at')->limit(25)->get()
                            ->mapWithKeys(fn (Event $event): array => [$event->id => $event->starts_at->isoFormat('L').' · '.$event->title])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Event::query()->find($value)?->title),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => $zones->linkEvent($this->actor(), $this->zone(), Event::query()->findOrFail($data['event_id'])))),
            Action::make('archive')->label(fn (): string => $this->zone()->isArchived() ? $g('restore') : $g('archive'))
                ->icon('heroicon-o-archive-box')->color('danger')->requiresConfirmation()
                ->visible(fn (): bool => $this->mayManage())
                ->action(fn () => $this->attempt(fn () => $zones->archive($this->actor(), $this->zone(), ! $this->zone()->isArchived()))),
        ];
    }

    public function unlinkEventAction(): Action
    {
        return Action::make('unlinkEvent')->label(__('geo.ui.unlink'))->requiresConfirmation()
            ->action(fn (array $arguments) => $this->attempt(fn () => app(ManageZones::class)
                ->unlinkEvent($this->actor(), $this->zone(), Event::query()->findOrFail($arguments['event'] ?? 0))));
    }
}
