<?php

namespace Database\Seeders\Demo;

use App\Domain\CRM\SegmentQuery;
use App\Domain\Events\Actions\EventParticipation;
use App\Domain\Events\Actions\ManageEvents;
use App\Domain\Events\CalendarExport;
use App\Domain\Events\Models\CalendarFeed;
use App\Domain\Events\Models\Event;
use App\Domain\Events\Models\EventAttendee;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Events of the demo world (plan 5.2): a past one with attendance, results and a photo report; a coming one with
 * all three answers; a weekly series; a closed group event and one in the secret group; a private one with a
 * withdrawn invitation; a cancelled one; calendar subscriptions.
 */
class EventsDemoSeeder extends Seeder
{
    use DemoSteps;

    public const string CANVASSING = 'Ieșire de agitație în sectorul Centru';

    public const string ASSEMBLY = 'Adunarea generală a organizației regionale Chișinău';

    public const string WEEKLY = 'Ședința săptămânală a statului-major';

    public const string GROUP_PLANNING = 'Planificarea lunii — filiala A';

    public const string SECRET = 'Consultări privind listele';

    public const string PRIVATE = 'Pregătirea vizitei delegației';

    public const string CANCELLED = 'Субботник в парке «Ботаника»';

    public const string BALTI = 'Instruire pentru voluntarii din Bălți';

    public static function event(string $title): Event
    {
        return Event::query()->where('title', $title)->orderBy('starts_at')->firstOrFail();
    }

    public function run(): void
    {
        try {
            $this->at(21, fn () => $this->weeklySeries());
            $this->at(20, fn () => $this->pastCanvassing());
            $this->at(8, fn () => $this->baltiTraining());
            $this->at(6, fn () => $this->cancelled());
            $this->at(5, fn () => $this->comingAssembly());
            $this->at(4, fn () => $this->groupEvents());
            $this->at(2, fn () => $this->privateWithWithdrawnInvitation());
            $this->at(1, fn () => $this->subscriptions());
        } finally {
            $this->resetClock();
        }
    }

    /**
     * @template T
     *
     * @param  callable(User): T  $step
     * @return T
     */
    private function by(string $key, callable $step): mixed
    {
        $user = Personas::user($key);

        return $this->as($user, fn () => $step($user));
    }

    private function person(string $key): Person
    {
        return Personas::user($key)->person;
    }

    private function answer(string $key, Event $event, string $answer, ?string $comment = null): void
    {
        $this->by($key, fn (User $user) => app(EventParticipation::class)->respond($user, $event, $answer, $comment));
    }

    /**
     * A weekly staff meeting of the region: eight Mondays in a row, three already held.
     */
    private function weeklySeries(): void
    {
        $first = $this->by('chisinau_head', fn (User $mihai): Event => app(ManageEvents::class)->create($mihai, [
            'title' => self::WEEKLY, 'type_code' => 'staff_meeting', 'starts_at' => now()->addDay()->setTime(9, 0), 'ends_at' => now()->addDay()->setTime(10, 0),
            'location' => 'Sediul regional, sala mică', 'description' => 'Ordinea de zi — în chatul șefilor de filiale.',
            'reminder_minutes' => [60], 'recurrence' => ['frequency' => 'weekly', 'until' => now()->addDays(56)],
        ]));
        $heads = [$this->person('branch_a_head')->id, $this->person('branch_b_head')->id];
        $series = Event::query()->where('series_id', $first->series_id)->orderBy('starts_at')->get();
        foreach ($series as $occurrence) {
            $this->by('chisinau_head', fn (User $mihai) => app(EventParticipation::class)->invite($mihai, $occurrence, $heads));
        }
        // The meetings already held: who was there.
        foreach ([14 => 0, 7 => 1] as $daysAgo => $index) {
            $this->at($daysAgo, function () use ($series, $index): void {
                foreach (['branch_a_head', 'branch_b_head'] as $key) {
                    $this->by('chisinau_head', fn (User $mihai) => app(EventParticipation::class)->markAttendance($mihai, $series[$index], $this->person($key), $key === 'branch_a_head' || $index === 0));
                }
            });
        }
        // The next one: one head is coming, the other has not answered.
        $next = $series->first(fn (Event $event): bool => $event->starts_at->greaterThan($this->demoNow ?? now()));
        if ($next !== null) {
            $this->at(1, fn () => $this->answer('branch_a_head', $next, EventAttendee::GOING));
        }
    }

    /**
     * Two weeks ago: invited in bulk, reminded by the scheduler, held, attendance marked — also two supporters
     * without accounts, — results and a photo published.
     */
    private function pastCanvassing(): void
    {
        $event = $this->by('branch_a_head', function (User $ana): Event {
            $event = app(ManageEvents::class)->create($ana, [
                'title' => self::CANVASSING, 'type_code' => 'canvassing', 'starts_at' => now()->addDays(6)->setTime(11, 0), 'ends_at' => now()->addDays(6)->setTime(14, 0),
                'location' => 'Scuarul Catedralei, lângă clopotniță', 'latitude' => 47.0245, 'longitude' => 28.8323,
                'description' => "Împărțim pliante și discutăm cu locuitorii.\nLuați apă și încălțăminte comodă.",
                'visibility' => Event::REGIONAL, 'territory_ids' => [Personas::territory('chisinau/sectorul-centru')->id],
            ]);
            app(EventParticipation::class)->inviteBulk($ana, $event, app(SegmentQuery::class)->build(['unit_id' => Personas::unit('branch_a')->id]));

            return $event;
        });
        $this->at(18, function () use ($event): void {
            $this->answer('branch_a_employee_1', $event, EventAttendee::GOING);
            $this->answer('branch_a_employee_2', $event, EventAttendee::GOING, 'Aduc pliantele de la tipografie');
            $this->answer('branch_a_employee_3', $event, EventAttendee::INTERESTED);
            $this->answer('volunteer', $event, EventAttendee::DECLINED, 'Am examen în ziua aceea');
        });
        // The morning of the event: both reminders are due and go out once.
        $this->at(14, fn () => Artisan::call('events:tick'));

        $this->at(13, function () use ($event): void {
            $participation = app(EventParticipation::class);
            $this->by('branch_a_head', function (User $ana) use ($event, $participation): void {
                foreach (['branch_a_employee_1', 'branch_a_employee_2', 'branch_a_employee_3'] as $key) {
                    $participation->markAttendance($ana, $event, $this->person($key), true);
                }
                // Supporters from the CRM who joined on the spot: a visit becomes a fact in their feed.
                foreach (['doina', 'petru'] as $key) {
                    $participation->markAttendance($ana, $event, CrmDemoSeeder::person($key), true);
                }
            });

            $photo = tempnam(sys_get_temp_dir(), 'demo');
            file_put_contents($photo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
            $report = tempnam(sys_get_temp_dir(), 'demo');
            file_put_contents($report, "Pliante împărțite: 420\nDiscuții: 63\nContacte noi: 11\n");
            $this->by('branch_a_head', fn (User $ana) => app(ManageEvents::class)->publishResults($ana, $event,
                "Am împărțit 420 de pliante și am discutat cu 63 de locuitori.\n11 persoane au lăsat datele de contact — sunt deja în CRM.",
                [['source' => $report, 'name' => 'raport-iesire.txt', 'mime' => 'text/plain']],
                [['source' => $photo, 'name' => 'echipa-la-catedrala.png', 'mime' => 'image/png']],
            ));
            @unlink($photo);
            @unlink($report);
        });
    }

    private function baltiTraining(): void
    {
        $event = $this->by('balti_head', fn (User $nicolae): Event => app(ManageEvents::class)->create($nicolae, [
            'title' => self::BALTI, 'type_code' => 'training', 'starts_at' => now()->addDays(17)->setTime(15, 0), 'ends_at' => now()->addDays(17)->setTime(18, 0),
            'location' => 'Casa de Cultură, sala 2', 'visibility' => Event::REGIONAL, 'territory_ids' => [Personas::territory('balti')->id],
        ]));
        $this->at(7, fn () => $this->answer('balti_employee_1', $event, EventAttendee::GOING));
        $this->at(7, fn () => $this->answer('balti_employee_2', $event, EventAttendee::INTERESTED));
    }

    private function cancelled(): void
    {
        $event = $this->by('branch_b_head', fn (User $pavel): Event => app(ManageEvents::class)->create($pavel, [
            'title' => self::CANCELLED, 'type_code' => 'meeting', 'starts_at' => now()->addDays(9)->setTime(10, 0),
            'location' => 'Вход со стороны бул. Дачия', 'visibility' => Event::REGIONAL, 'territory_ids' => [Personas::territory('chisinau/sectorul-botanica')->id],
        ]));
        $this->at(5, fn () => $this->answer('branch_b_employee_1', $event, EventAttendee::GOING));
        $this->at(3, fn () => $this->by('branch_b_head', fn (User $pavel) => app(ManageEvents::class)->cancel($pavel, $event, 'Примэрия закрыла парк на благоустройство')));
    }

    /**
     * In five days: the whole region is invited; the answers are of all three kinds, and many have not answered.
     */
    private function comingAssembly(): void
    {
        $event = $this->by('chisinau_head', function (User $mihai): Event {
            $event = app(ManageEvents::class)->create($mihai, [
                'title' => self::ASSEMBLY, 'type_code' => 'assembly', 'starts_at' => now()->addDays(10)->setTime(18, 0), 'ends_at' => now()->addDays(10)->setTime(20, 0),
                'location' => 'Sediul regional, sala mare', 'latitude' => 47.0105, 'longitude' => 28.8638,
                'description' => 'Raportul trimestrial, planul pe luna viitoare, întrebări.',
                'visibility' => Event::REGIONAL, 'territory_ids' => [Personas::territory('chisinau')->id], 'reminder_minutes' => [1440, 180],
            ]);
            app(EventParticipation::class)->inviteBulk($mihai, $event, Person::query());

            return $event;
        });
        $this->at(4, function () use ($event): void {
            $this->answer('branch_a_head', $event, EventAttendee::GOING);
            $this->answer('branch_b_head', $event, EventAttendee::GOING);
            $this->answer('branch_a_employee_2', $event, EventAttendee::GOING);
            $this->answer('branch_b_employee_2', $event, EventAttendee::GOING);
        });
        $this->at(3, function () use ($event): void {
            $this->answer('branch_a_employee_1', $event, EventAttendee::INTERESTED, 'Depinde de programul de la filială');
            $this->answer('branch_b_employee_3', $event, EventAttendee::INTERESTED);
            $this->answer('branch_b_employee_1', $event, EventAttendee::DECLINED, 'В этот день я в командировке');
        });
    }

    private function groupEvents(): void
    {
        // Closed group: seen by its members only.
        $planning = $this->by('branch_a_head', fn (User $ana): Event => app(ManageEvents::class)->create($ana, [
            'title' => self::GROUP_PLANNING, 'type_code' => 'meeting', 'starts_at' => now()->addDays(7)->setTime(18, 0),
            'location' => 'Sediul filialei', 'visibility' => Event::GROUP, 'group_ids' => [SocialDemoSeeder::group(SocialDemoSeeder::GROUP_CLOSED)->id],
        ]));
        $this->at(3, fn () => $this->answer('branch_a_employee_2', $planning, EventAttendee::GOING));

        // Secret group: outside the group the event does not exist.
        $this->at(3, function (): void {
            $secret = $this->by('org_head', fn (User $elena): Event => app(ManageEvents::class)->create($elena, [
                'title' => self::SECRET, 'type_code' => 'meeting', 'starts_at' => now()->addDays(9)->setTime(19, 0),
                'location' => 'Online', 'visibility' => Event::GROUP, 'group_ids' => [SocialDemoSeeder::group(SocialDemoSeeder::GROUP_SECRET)->id],
            ]));
            $this->answer('chisinau_head', $secret, EventAttendee::GOING);
        });
    }

    /**
     * A private event: only the invited see it. Sergiu was invited by mistake — the invitation is withdrawn, and
     * the notification he had received keeps the fact, not the title.
     */
    private function privateWithWithdrawnInvitation(): void
    {
        $participation = app(EventParticipation::class);
        $event = $this->by('branch_a_head', function (User $ana) use ($participation): Event {
            $event = app(ManageEvents::class)->create($ana, [
                'title' => self::PRIVATE, 'type_code' => 'meeting', 'starts_at' => now()->addDays(8)->setTime(16, 0), 'location' => 'Biroul șefei de filială',
            ]);
            $participation->invite($ana, $event, [$this->person('branch_a_employee_1')->id, $this->person('branch_a_employee_3')->id]);

            return $event;
        });
        $this->at(1, function () use ($event, $participation): void {
            $this->answer('branch_a_employee_1', $event, EventAttendee::GOING);
            $this->by('branch_a_head', fn (User $ana) => $participation->uninvite($ana, $event, $this->person('branch_a_employee_3')));
        });
    }

    private function subscriptions(): void
    {
        $calendar = app(CalendarExport::class);
        $this->by('branch_a_employee_1', fn (User $ion) => $calendar->createFeed($ion, CalendarFeed::PERSONAL));
        $this->by('chisinau_head', fn (User $mihai) => $calendar->createFeed($mihai, CalendarFeed::TERRITORY, Personas::territory('chisinau')->id));
        // Created and revoked: the address no longer opens anything.
        $this->by('branch_a_employee_2', function (User $maria) use ($calendar): void {
            $created = $calendar->createFeed($maria, CalendarFeed::GROUP, SocialDemoSeeder::group(SocialDemoSeeder::GROUP_OPEN)->id);
            $this->at(0, fn () => $calendar->revokeFeed($maria, $created['feed']));
        });
    }
}
