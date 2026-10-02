<?php

declare(strict_types=1);

namespace App\Domain\Geo\Notifications;

use App\Domain\Access\AuthorizationService;
use App\Domain\Events\EventVisibility;
use App\Domain\Events\Models\Event;
use App\Domain\Geo\Models\GeoZoneCrossing;
use App\Domain\Geo\Models\LocationShare;
use App\Domain\Geo\Models\Vehicle;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Concerns\RoutesByPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notices of the field work: an agitator got a house; an event is to be held inside a geozone; a participant or
 * a vehicle crossed the border of a geozone. A notice about an event or about somebody's location is delivered
 * only to those who may see that event or that location (ТЗ §37).
 */
final class FieldNotice extends Notification implements ShouldQueue
{
    use Queueable;
    use RoutesByPreference;

    public const string ASSIGNED = 'assigned';

    public const string ZONE_EVENT = 'zone_event';

    public const string ZONE_CROSSING = 'zone_crossing';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly string $kind, public readonly array $data) {}

    public function category(): string
    {
        return 'field';
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return true;
        }

        return match ($this->kind) {
            self::ZONE_EVENT => ($event = Event::query()->find($this->data['event_id'] ?? null)) !== null
                && app(EventVisibility::class)->canSee($notifiable, $event),
            self::ZONE_CROSSING => $this->maySeeMover($notifiable),
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $payload = [
            'format' => 'filament',
            // Translated when delivered, in the language of the recipient.
            'title' => __('geo.notices.'.($this->kind === self::ZONE_CROSSING ? 'zone_'.$this->data['direction'] : $this->kind), array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $this->data)),
            'body' => (string) ($this->data['body'] ?? ''),
            'icon' => $this->kind === self::ASSIGNED ? 'heroicon-o-home-modern' : 'heroicon-o-map-pin',
            'iconColor' => 'info',
            'duration' => 'persistent',
            'kind' => 'field_'.$this->kind,
        ];
        if (isset($this->data['url'])) {
            $payload['actions'] = [[
                'name' => 'open', 'label' => __('notifications.open'), 'url' => (string) $this->data['url'], 'shouldMarkAsRead' => true,
            ]];
        }
        if ($this->kind === self::ZONE_EVENT) {
            $payload['subject'] = ['event', (int) $this->data['event_id']];
        }

        return $payload;
    }

    private function maySeeMover(User $user): bool
    {
        $authorization = app(AuthorizationService::class);
        if (($this->data['mover_type'] ?? null) === GeoZoneCrossing::VEHICLE) {
            $vehicle = Vehicle::query()->find($this->data['mover_id'] ?? null);

            return $vehicle !== null && $authorization->can($user, 'geo.vehicles.read', $vehicle);
        }
        $share = LocationShare::query()->find($this->data['share_id'] ?? null);

        return $share !== null && ($share->person_id === $user->person_id || $authorization->can($user, 'geo.locations.read', $share));
    }
}
