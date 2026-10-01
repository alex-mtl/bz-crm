<?php

namespace Database\Seeders\Demo;

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\Actions\RevokeRole;
use App\Domain\Access\Admission\InviteUser;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\UserRole;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\CRM\Actions\ManageAppeals;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Actions\ManagePipelines;
use App\Domain\CRM\Actions\ManageRelations;
use App\Domain\CRM\Actions\MergePeople;
use App\Domain\CRM\Actions\RecordInteraction;
use App\Domain\CRM\Duplicates;
use App\Domain\CRM\Exports\Exports;
use App\Domain\CRM\Imports\PeopleImport;
use App\Domain\CRM\Models\DuplicateCandidate;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\Pipeline;
use App\Domain\CRM\Models\PipelineStage;
use App\Domain\CRM\Segments;
use App\Domain\CustomObjects\CustomFields;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\Geo\Actions\ManageTerritories;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ManagePeople;
use App\Domain\People\Actions\RegisterCandidate;
use App\Domain\People\Models\Person;
use App\Domain\Profiles\Actions\ManageProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * The CRM of the demo world (plan 3.2): supporters and partners in several regions, relations between people,
 * two pipelines with leads on every stage (lost for different reasons, frozen, returned by the scheduler),
 * appeals in every status linked to people and tasks, deliberate duplicates (a pair by phone, a pair by e-mail,
 * a triple by name, one merge done, one pair dismissed), saved segments, an import with errors and a clean one.
 */
class CrmDemoSeeder extends Seeder
{
    use DemoSteps;

    public const string JOINING = 'aderare';

    public const string VOLUNTEERS = 'voluntari_eveniment';

    /**
     * Supporters and partners: key => [first, last, type, territory, unit key, creator persona, age, gender, locale, source, phone tail, has e-mail].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: string, 6: int, 7: string, 8: string, 9: string, 10: string, 11: bool}>
     */
    public const array PEOPLE = [
        'doina' => ['Doina', 'Vrabie', 'supporter', 'chisinau/sectorul-centru', 'branch_a', 'branch_a_head', 45, 'female', 'ro', 'event', '001', true],
        'petru' => ['Petru', 'Lungu', 'supporter', 'chisinau/sectorul-centru', 'branch_a', 'branch_a_head', 28, 'male', 'ro', 'website_form', '002', true],
        'galina' => ['Galina', 'Sîrbu', 'supporter', 'chisinau/sectorul-centru', 'branch_a', 'branch_a_head', 62, 'female', 'ru', 'door_to_door', '003', false],
        'andrei' => ['Andrei', 'Cazacu', 'supporter', 'chisinau/sectorul-botanica', 'branch_b', 'branch_b_head', 34, 'male', 'ro', 'recommendation', '004', true],
        'elena_b' => ['Elena', 'Rotaru', 'supporter', 'chisinau/sectorul-botanica', 'branch_b', 'branch_b_head', 51, 'female', 'ro', 'event', '005', false],
        'viorica' => ['Viorica', 'Mocanu', 'supporter', 'balti', 'balti', 'balti_head', 39, 'female', 'ro', 'website_form', '006', true],
        'serghei' => ['Serghei', 'Ivanov', 'supporter', 'balti', 'balti', 'balti_head', 47, 'male', 'ru', 'phone_call', '007', false],
        'tudor' => ['Tudor', 'Bostan', 'supporter', 'cahul', 'north_south', 'org_head', 31, 'male', 'ro', 'social_media', '008', true],
        'larisa' => ['Larisa', 'Guzun', 'supporter', 'ungheni', null, 'org_head', 55, 'female', 'ro', 'phone_call', '009', false],
        'partner_print' => ['Ion', 'Ciubotaru', 'partner', 'chisinau', 'branch_a', 'hr', 42, 'male', 'ro', 'recommendation', '010', true],
        'partner_balti' => ['Mariana', 'Lupan', 'partner', 'balti', 'balti', 'hr', 36, 'female', 'ro', 'event', '011', true],
    ];

    public static function person(string $key): Person
    {
        return Person::query()->where('phone', self::phone(self::PEOPLE[$key][10]))->whereNull('duplicate_of_person_id')->orderBy('id')->firstOrFail();
    }

    public static function pipeline(string $code): Pipeline
    {
        return Pipeline::query()->where('code', $code)->firstOrFail();
    }

    public static function stage(string $pipeline, string $code): PipelineStage
    {
        return self::pipeline($pipeline)->stages()->where('code', $code)->firstOrFail();
    }

    private static function phone(string $tail): string
    {
        return '37369201'.$tail;
    }

    public function run(): void
    {
        try {
            $this->at(60, fn () => $this->operatorOfChisinau());
            $this->at(60, fn () => $this->customFields());
            $this->at(59, fn () => $this->territoryResponsibles());
            $this->at(58, fn () => $this->pipelines());
            $this->at(50, fn () => $this->people());
            $this->at(48, fn () => $this->relations());
            $this->at(40, fn () => $this->joiningLeads());
            $this->at(25, fn () => $this->volunteerLeads());
            $this->at(20, fn () => $this->interactions());
            $this->at(15, fn () => $this->appeals());
            $this->at(10, fn () => $this->frozenAndForgotten());
            $this->at(8, fn () => $this->duplicates());
            $this->at(6, fn () => $this->segments());
            $this->at(4, fn () => $this->importsAndExport());
            $this->at(3, fn () => $this->newCandidates());
            $this->at(0, fn () => $this->todaysCandidate());
            $this->at(0, fn () => Artisan::call('crm:unfreeze-leads'));
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

    private function pid(string $key): int
    {
        return Personas::user($key)->person_id;
    }

    /**
     * The inbox operator works with one region (plan 3.3 "оператор региона"): the role is re-assigned with the
     * territory Chișinău as its scope.
     */
    private function operatorOfChisinau(): void
    {
        $this->by('super_admin', function (User $admin): void {
            $stela = Personas::user('inbox_operator');
            $role = Role::query()->where('code', 'inbox_operator')->firstOrFail();
            foreach (UserRole::query()->where('user_id', $stela->id)->where('role_id', $role->id)->whereNull('scope_type')->get() as $organizationWide) {
                app(RevokeRole::class)($admin, $organizationWide);
            }
            app(AssignRole::class)($admin, $stela, $role, scope: ScopeType::Territory, scopeId: Personas::territory('chisinau')->id);
        });
    }

    /**
     * Who answers for which territory (ФО §6.11): the branch heads for their sectors, the region head for the
     * whole municipality — so a sector without its own responsible falls to him.
     */
    private function territoryResponsibles(): void
    {
        $this->by('super_admin', function (User $admin): void {
            $territories = app(ManageTerritories::class);
            foreach ([
                'chisinau/sectorul-centru' => 'branch_a_head', 'chisinau/sectorul-botanica' => 'branch_b_head',
                'chisinau' => 'chisinau_head', 'balti' => 'balti_head',
            ] as $code => $persona) {
                $territories->assignResponsible($admin, Personas::territory($code), Personas::user($persona)->person);
            }
        });
    }

    private function customFields(): void
    {
        $this->by('catalog_admin', function (User $liliana): void {
            $fields = app(CustomFields::class);
            $fields->saveDefinition($liliana, CustomField::PERSON, [
                'code' => 'has_car', 'names' => ['ro' => 'Poate ajuta cu transportul', 'ru' => 'Может помочь с транспортом', 'en' => 'Can help with transport'],
                'field_type' => 'bool', 'applies_to' => ['supporter', 'volunteer'],
            ], 'ro');
            $fields->saveDefinition($liliana, CustomField::PERSON, [
                'code' => 'interest', 'names' => ['ro' => 'Domeniu de interes', 'ru' => 'Сфера интересов', 'en' => 'Area of interest'],
                'field_type' => 'select', 'options' => [
                    ['value' => 'ecology', 'ro' => 'Ecologie', 'ru' => 'Экология', 'en' => 'Ecology'],
                    ['value' => 'education', 'ro' => 'Educație', 'ru' => 'Образование', 'en' => 'Education'],
                    ['value' => 'infrastructure', 'ro' => 'Infrastructură', 'ru' => 'Инфраструктура', 'en' => 'Infrastructure'],
                ],
            ], 'ro');
        });
    }

    private function pipelines(): void
    {
        $stage = fn (string $code, string $ro, string $ru, string $en, string $kind = 'open', ?int $slaHours = null): array => [
            'code' => $code, 'names' => ['ro' => $ro, 'ru' => $ru, 'en' => $en], 'kind' => $kind, 'sla_hours' => $slaHours,
        ];

        $this->by('catalog_admin', function (User $liliana) use ($stage): void {
            $pipelines = app(ManagePipelines::class);
            $pipelines->save($liliana, [
                'code' => self::JOINING, 'sort_order' => 10,
                // Д-22: a new candidate enters the pipeline by itself; the responsible is found by territory.
                'auto_enroll_types' => ['candidate'], 'auto_assign_by_territory' => true,
                'names' => ['ro' => 'Aderare la organizație', 'ru' => 'Вступление в организацию', 'en' => 'Joining the organization'],
            ], 'ro', [
                $stage('new', 'Adresare nouă', 'Новое обращение', 'New request', slaHours: 24),
                $stage('contacted', 'Contact stabilit', 'Контакт установлен', 'Contact made', slaHours: 72),
                $stage('meeting', 'Întâlnire', 'Встреча', 'Meeting', slaHours: 24 * 14),
                $stage('training', 'Instruire', 'Обучение', 'Training'),
                $stage('mentor', 'Mentor desemnat', 'Наставник назначен', 'Mentor assigned'),
                $stage('active', 'Membru activ', 'Активный участник', 'Active member', 'won'),
                $stage('lost', 'Pierdut', 'Потерян', 'Lost', 'lost'),
            ]);
            $pipelines->save($liliana, [
                'code' => self::VOLUNTEERS, 'sort_order' => 20,
                'names' => ['ro' => 'Voluntari pentru eveniment', 'ru' => 'Волонтёры мероприятия', 'en' => 'Event volunteers'],
            ], 'ro', [
                $stage('signed_up', 'Înscris', 'Записался', 'Signed up'),
                $stage('confirmed', 'Confirmat', 'Подтверждён', 'Confirmed'),
                $stage('briefed', 'Instruit', 'Проинструктирован', 'Briefed'),
                $stage('took_part', 'A participat', 'Участвовал', 'Took part', 'won'),
                $stage('withdrew', 'A renunțat', 'Отказался', 'Withdrew', 'lost'),
            ]);
        });
    }

    private function people(): void
    {
        foreach (self::PEOPLE as [$first, $last, $type, $territory, $unit, $creator, $age, $gender, $locale, $source, $tail, $hasEmail]) {
            $this->by($creator, function (User $actor) use ($first, $last, $type, $territory, $unit, $age, $gender, $locale, $source, $tail, $hasEmail): void {
                $person = app(ManagePeople::class)->create($actor, [
                    'first_name' => $first, 'last_name' => $last, 'person_type' => $type, 'preferred_locale' => $locale,
                    'territory_id' => Personas::territory($territory)->id,
                    'responsible_unit_id' => $unit !== null ? Personas::unit($unit)->id : null,
                    'source_code' => $source, 'phone' => '+'.self::phone($tail),
                    'email' => $hasEmail ? Personas::email($first, $last, '.crm') : null,
                ]);
                app(ManageProfile::class)->updateFor($actor, $person, [
                    // A day past the birthday, so the age holds on any day the world is rebuilt.
                    'birth_date' => $this->demoNow?->copy()->subYears($age)->subDays(40)->toDateString(),
                    'gender' => $gender,
                    // The language of the card first, Romanian always among them.
                    'languages' => array_values(array_unique([$locale, 'ro'])),
                ], [
                    ['contact_type' => 'phone', 'value' => '+'.self::phone($tail), 'visibility' => 'all', 'is_preferred' => ! $hasEmail],
                    ...($hasEmail ? [['contact_type' => 'email', 'value' => Personas::email($first, $last, '.crm'), 'visibility' => 'all', 'is_preferred' => true]] : []),
                ]);
            });
        }

        $this->by('branch_a_head', function (): void {
            $fields = app(CustomFields::class);
            $fields->store(CustomField::PERSON, self::person('doina')->id, ['has_car' => true, 'interest' => 'ecology'], 'supporter');
            $fields->store(CustomField::PERSON, self::person('petru')->id, ['interest' => 'education'], 'supporter');
        });

        // The candidate from the site form (phase 1) gets a place: now the branch can work with her.
        $this->by('hr', function (User $natalia): void {
            $lilia = Person::query()->where('first_name', Personas::CANDIDATE[0])->where('last_name', Personas::CANDIDATE[1])->firstOrFail();
            app(ManagePeople::class)->update($natalia, $lilia, [
                'territory_id' => Personas::territory('chisinau/sectorul-centru')->id,
                'responsible_unit_id' => Personas::unit('branch_a')->id, 'source_code' => 'website_form',
            ]);
        });
    }

    private function relations(): void
    {
        $this->by('hr', function (User $natalia): void {
            $relations = app(ManageRelations::class);
            $relations->add($natalia, self::person('doina'), self::person('petru'), 'invited', 'L-a adus la întâlnirea din parc');
            $relations->add($natalia, self::person('andrei'), self::person('elena_b'), 'neighbour');
            $relations->add($natalia, self::person('galina'), self::person('doina'), 'colleague', 'Lucrează la aceeași școală');
            $relations->add($natalia, self::person('partner_print'), Personas::user('branch_a_employee_1')->person, 'friend');
        });
    }

    /**
     * "Joining the organization": a lead on every stage, won, lost for different reasons, frozen.
     */
    private function joiningLeads(): void
    {
        $leads = app(ManageLeads::class);
        $joining = self::pipeline(self::JOINING);
        $stage = fn (string $code): PipelineStage => self::stage(self::JOINING, $code);
        $lilia = Person::query()->where('first_name', Personas::CANDIDATE[0])->where('last_name', Personas::CANDIDATE[1])->firstOrFail();

        // Centru: Ana creates, her staff work the leads they are responsible for.
        $doina = $this->by('branch_a_head', fn (User $ana) => $leads->create($ana, $joining, self::person('doina'), ['responsible_person_id' => $this->pid('branch_a_employee_1')]));
        $this->by('branch_a_employee_1', function (User $ion) use ($leads, $doina, $stage): void {
            $leads->move($ion, $doina, $stage('contacted'), 'A răspuns la telefon, este interesată');
            $leads->move($ion, $doina->fresh() ?? $doina, $stage('meeting'), 'Întâlnire stabilită pentru sâmbătă');
        });
        // A new request taken by the operator: nobody is responsible yet.
        $this->by('inbox_operator', fn (User $stela) => $leads->create($stela, $joining, self::person('petru'), ['title' => 'Cerere de pe site']));
        $galina = $this->by('branch_a_head', fn (User $ana) => $leads->create($ana, $joining, self::person('galina'), ['responsible_person_id' => $this->pid('branch_a_employee_2')]));
        $this->by('branch_a_employee_2', function (User $maria) use ($leads, $galina, $stage): void {
            foreach (['contacted', 'meeting', 'training', 'mentor'] as $code) {
                $leads->move($maria, $galina->fresh() ?? $galina, $stage($code));
            }
        });
        $candidate = $this->by('branch_a_head', fn (User $ana) => $leads->create($ana, $joining, $lilia, ['responsible_person_id' => $this->pid('branch_a_employee_1')]));
        $this->by('branch_a_employee_1', fn (User $ion) => $leads->move($ion, $candidate, $stage('contacted')));

        // Botanica: one became an active member, one was lost.
        $andrei = $this->by('branch_b_head', fn (User $pavel) => $leads->create($pavel, $joining, self::person('andrei'), ['responsible_person_id' => $this->pid('branch_b_employee_1')]));
        $this->by('branch_b_employee_1', function (User $olga) use ($leads, $andrei, $stage): void {
            foreach (['contacted', 'meeting', 'training', 'mentor', 'active'] as $code) {
                $leads->move($olga, $andrei->fresh() ?? $andrei, $stage($code));
            }
        });
        // He went all the way: the supporter becomes a member (life cycle of a person, Д-22).
        $this->by('branch_b_head', fn (User $pavel) => app(ManagePeople::class)->update($pavel, self::person('andrei'), ['person_type' => 'member']));
        $this->by('branch_b_head', function (User $pavel) use ($leads, $joining): void {
            $lead = $leads->create($pavel, $joining, self::person('elena_b'));
            $leads->lose($pavel, $lead, 'no_time', 'Lucrează în două schimburi');
        });

        // Bălți: one frozen until a date in the future, one lost.
        $this->by('balti_head', function (User $nicolae) use ($leads, $joining, $stage): void {
            $viorica = $leads->create($nicolae, $joining, self::person('viorica'), ['responsible_person_id' => $this->pid('balti_employee_2')]);
            $leads->move($nicolae, $viorica, $stage('contacted'));
            $leads->freeze($nicolae, $viorica->fresh() ?? $viorica, ($this->demoNow ?? now())->copy()->addDays(20), 'Plecată la muncă peste hotare până la sfârșitul lunii');
            $serghei = $leads->create($nicolae, $joining, self::person('serghei'));
            $leads->lose($nicolae, $serghei, 'moved_away', 'S-a mutat la Chișinău');
        });

        // Cahul: the lead of the North–South administration.
        $tudor = $this->by('org_head', fn (User $elena) => $leads->create($elena, $joining, self::person('tudor'), ['responsible_person_id' => $this->pid('north_south_employee')]));
        $this->by('north_south_employee', function (User $andrian) use ($leads, $tudor, $stage): void {
            foreach (['contacted', 'meeting', 'training'] as $code) {
                $leads->move($andrian, $tudor->fresh() ?? $tudor, $stage($code));
            }
        });
    }

    private function volunteerLeads(): void
    {
        $leads = app(ManageLeads::class);
        $volunteers = self::pipeline(self::VOLUNTEERS);
        $stage = fn (string $code): PipelineStage => self::stage(self::VOLUNTEERS, $code);

        $this->by('branch_a_head', function (User $ana) use ($leads, $volunteers, $stage): void {
            $petru = $leads->create($ana, $volunteers, self::person('petru'), ['responsible_person_id' => $this->pid('branch_a_employee_3')]);
            $leads->move($ana, $petru, $stage('confirmed'));
            $doina = $leads->create($ana, $volunteers, self::person('doina'), ['responsible_person_id' => $this->pid('branch_a_employee_3')]);
            foreach (['confirmed', 'briefed', 'took_part'] as $code) {
                $leads->move($ana, $doina->fresh() ?? $doina, $stage($code));
            }
        });
        $this->by('branch_b_head', function (User $pavel) use ($leads, $volunteers): void {
            $andrei = $leads->create($pavel, $volunteers, self::person('andrei'));
            $leads->lose($pavel, $andrei, 'other', 'În ziua evenimentului este plecat');
        });
    }

    private function interactions(): void
    {
        $record = app(RecordInteraction::class);
        $ago = fn (int $days) => now()->subDays($days);

        $this->by('branch_a_employee_1', function (User $ion) use ($record, $ago): void {
            $lead = Lead::query()->where('person_id', self::person('doina')->id)->where('pipeline_id', self::pipeline(self::JOINING)->id)->firstOrFail();
            $record($ion, self::person('doina'), 'call', ['direction' => 'out', 'duration_minutes' => 7, 'summary' => 'Prima discuție: vrea să afle despre activitatea filialei', 'occurred_at' => $ago(18)], $lead);
            $record($ion, self::person('doina'), 'meeting', ['summary' => 'Întâlnire la sediu, a primit materialele', 'duration_minutes' => 40, 'occurred_at' => $ago(12)], $lead);
        });
        $this->by('inbox_operator', fn (User $stela) => $record($stela, self::person('petru'), 'call', [
            'direction' => 'in', 'duration_minutes' => 4, 'summary' => 'A sunat să confirme cererea de pe site', 'occurred_at' => $ago(17),
        ]));
        $this->by('branch_a_head', function (User $ana) use ($record, $ago): void {
            $record($ana, self::person('galina'), 'door_visit', ['summary' => 'Vizită la domiciliu în cadrul campaniei', 'occurred_at' => $ago(16)]);
            foreach (['doina', 'petru'] as $key) {
                $record($ana, self::person($key), 'event_visit', ['summary' => 'A participat la întâlnirea din parcul „Valea Morilor”', 'occurred_at' => $ago(10)]);
            }
        });
        $this->by('branch_b_employee_1', fn (User $olga) => $record($olga, self::person('andrei'), 'message', [
            'direction' => 'out', 'summary' => 'I-am trimis programul instruirii', 'occurred_at' => $ago(9),
        ]));
        $this->by('balti_head', fn (User $nicolae) => $record($nicolae, self::person('viorica'), 'call', [
            'direction' => 'out', 'summary' => 'Revine în țară la sfârșitul lunii, reluăm atunci', 'occurred_at' => $ago(15),
        ]));
    }

    /**
     * Appeals in every status (ФО §6.9.3), linked to people and tasks; one is overdue.
     * What happens at the time of this step comes first: a nested at() ends by returning the clock to "now".
     */
    private function appeals(): void
    {
        $appeals = app(ManageAppeals::class);

        // in progress, overdue — a complaint in Botanica registered 15 days ago (10 days to answer).
        $pothole = $this->by('branch_b_head', fn (User $pavel) => $appeals->register($pavel, [
            'title' => 'Gropi pe str. Independenței', 'type_code' => 'complaint', 'source_code' => 'phone_call', 'priority_code' => 'high',
            'body' => 'Locuitorii blocului 14 se plâng de gropile din fața intrării.',
            'person_id' => self::person('andrei')->id, 'responsible_person_id' => $this->pid('branch_b_employee_1'),
        ]));
        $this->by('branch_b_employee_1', fn (User $olga) => $appeals->changeStatus($olga, $pothole, 'in_progress'));

        // done — 12 days ago: a question answered by the responsible employee.
        $this->at(12, function () use ($appeals): void {
            $question = $this->by('inbox_operator', fn (User $stela) => $appeals->register($stela, [
                'title' => 'Cum pot deveni membru?', 'type_code' => 'question', 'source_code' => 'website_form',
                'person_id' => self::person('petru')->id, 'responsible_person_id' => $this->pid('branch_a_employee_1'),
            ]));
            $this->by('branch_a_employee_1', function (User $ion) use ($appeals, $question): void {
                $appeals->changeStatus($ion, $question, 'in_progress');
                $appeals->changeStatus($ion, $question->fresh() ?? $question, 'done', 'I-am explicat pașii și l-am invitat la întâlnirea de sâmbătă.');
            });
        });

        // in progress with a task — a request for help in Centru.
        $this->at(4, function () use ($appeals): void {
            $help = $this->by('branch_a_head', fn (User $ana) => $appeals->register($ana, [
                'title' => 'Transport la policlinică', 'type_code' => 'help_request', 'source_code' => 'door_to_door',
                'body' => 'Doamna Galina are nevoie de transport la policlinică joi dimineața.',
                'person_id' => self::person('galina')->id, 'responsible_person_id' => $this->pid('branch_a_employee_3'),
            ]));
            $this->by('branch_a_employee_3', function (User $sergiu) use ($appeals, $help): void {
                $appeals->changeStatus($sergiu, $help, 'in_progress');
                $appeals->createTask($sergiu, $help->fresh() ?? $help, [
                    'title' => 'Transport pentru Galina Sîrbu la policlinică', 'type_code' => 'request',
                    'due_at' => ($this->demoNow ?? now())->copy()->addDays(2)->setTime(9, 0),
                ], [$sergiu->person_id]);
            });
        });

        // rejected — a commercial offer is not an appeal the organization handles.
        $this->at(7, function () use ($appeals): void {
            $offer = $this->by('branch_a_head', fn (User $ana) => $appeals->register($ana, [
                'title' => 'Ofertă de tipărire a materialelor', 'type_code' => 'proposal', 'person_id' => self::person('partner_print')->id,
            ]));
            $this->by('branch_a_head', fn (User $ana) => $appeals->changeStatus($ana, $offer, 'rejected', 'Achizițiile se fac centralizat; oferta a fost transmisă aparatului central.'));
        });

        // new — registered yesterday, nobody is responsible yet.
        $this->at(1, function () use ($appeals): void {
            $this->by('inbox_operator', fn (User $stela) => $appeals->register($stela, [
                // High priority: the first response is due in 8 hours — and nobody has taken it yet.
                'title' => 'Iluminat stradal defect pe str. Bulgară', 'type_code' => 'complaint', 'source_code' => 'phone_call', 'priority_code' => 'high',
                'person_id' => self::person('doina')->id,
            ]));
            $this->by('balti_head', fn (User $nicolae) => $appeals->register($nicolae, [
                'title' => 'Cerere de înscriere la instruire', 'type_code' => 'website_request', 'source_code' => 'website_form',
                'person_id' => self::person('viorica')->id,
            ]));
        });
    }

    /**
     * A lead frozen until a date that has already passed: the scheduler returns it to work and tells the responsible.
     */
    private function frozenAndForgotten(): void
    {
        $this->by('org_head', function (User $elena): void {
            $leads = app(ManageLeads::class);
            $larisa = $leads->create($elena, self::pipeline(self::JOINING), self::person('larisa'), ['responsible_person_id' => $this->pid('central_employee')]);
            $leads->freeze($elena, $larisa, now()->addDays(8), 'A rugat să revenim peste o săptămână');
        });
    }

    private function duplicates(): void
    {
        $people = app(ManagePeople::class);
        $centru = Personas::territory('chisinau/sectorul-centru')->id;
        $new = fn (array $data): array => ['person_type' => 'supporter', 'source_code' => 'event', ...$data];

        // A pair by phone (Centru) and a pair by e-mail (Botanica) — both wait for a decision.
        $this->by('branch_a_head', fn (User $ana) => $people->create($ana, $new([
            'first_name' => 'Doina', 'last_name' => 'Vrabii', 'phone' => '+'.self::phone('001'), 'territory_id' => $centru, 'responsible_unit_id' => Personas::unit('branch_a')->id,
        ])));
        $this->by('branch_b_head', fn (User $pavel) => $people->create($pavel, $new([
            'first_name' => 'A.', 'last_name' => 'Cazacu', 'email' => Personas::email('Andrei', 'Cazacu', '.crm'),
            'territory_id' => Personas::territory('chisinau/sectorul-botanica')->id, 'responsible_unit_id' => Personas::unit('branch_b')->id,
        ])));

        // A triple by name in Bălți: three cards "Serghei Ivanov" — three pairs.
        $this->by('balti_head', function (User $nicolae) use ($people, $new): void {
            foreach (['021', '022'] as $tail) {
                $people->create($nicolae, $new([
                    'first_name' => 'Serghei', 'last_name' => 'Ivanov', 'phone' => '+'.self::phone($tail),
                    'territory_id' => Personas::territory('balti')->id, 'responsible_unit_id' => Personas::unit('balti')->id,
                ]));
            }
            // A namesake of the supporter from Botanica — these really are two different people.
            $people->create($nicolae, $new([
                'first_name' => 'Elena', 'last_name' => 'Rotaru', 'phone' => '+'.self::phone('023'),
                'territory_id' => Personas::territory('balti')->id, 'responsible_unit_id' => Personas::unit('balti')->id,
            ]));
        });

        // A merge that is already done: the second card of Tudor had its own lead and a call — both now belong to the kept card.
        $tudor = self::person('tudor');
        $second = $this->by('org_head', function (User $elena) use ($people, $new): Person {
            $card = $people->create($elena, $new([
                'first_name' => 'Tudor', 'last_name' => 'Bostan', 'phone' => '+'.self::phone('008'), 'source_code' => 'phone_call',
                'territory_id' => Personas::territory('cahul')->id, 'responsible_unit_id' => Personas::unit('north_south')->id,
            ]));
            app(ManageLeads::class)->create($elena, self::pipeline(self::VOLUNTEERS), $card);
            app(RecordInteraction::class)($elena, $card, 'call', ['direction' => 'in', 'summary' => 'A sunat să se înscrie ca voluntar']);

            return $card;
        });

        $this->at(7, function () use ($tudor, $second): void {
            $this->by('hr', function (User $natalia) use ($tudor, $second): void {
                app(MergePeople::class)($natalia, $tudor, $second);
                $namesakes = DuplicateCandidate::query()->where('status', DuplicateCandidate::OPEN)
                    ->whereIn('person_a_id', [self::person('elena_b')->id])->firstOrFail();
                app(Duplicates::class)->dismiss($natalia, $namesakes);
            });
        });
    }

    /**
     * Д-22: candidates created after the pipeline was set up enter it by themselves. In Centru the lead goes to Ana
     * (responsible for the sector); Buiucani has no responsible of its own — the lead goes up to Mihai (Chișinău).
     * And an invitation for an existing card: Petru is offered an account, his card keeps its history.
     */
    private function newCandidates(): void
    {
        $this->by('hr', function (User $natalia): void {
            foreach ([['Cristian', 'Demo-Olaru', 'chisinau/sectorul-centru', '031'], ['Daniela', 'Demo-Pascal', 'chisinau/sectorul-buiucani', '032']] as [$first, $last, $territory, $tail]) {
                app(ManagePeople::class)->create($natalia, [
                    'first_name' => $first, 'last_name' => $last, 'person_type' => 'candidate', 'source_code' => 'event',
                    'territory_id' => Personas::territory($territory)->id, 'phone' => '+'.self::phone($tail),
                ]);
            }
        });

        $this->at(2, fn () => $this->by('branch_a_head', fn (User $ana) => app(InviteUser::class)(
            $ana, Personas::email('Petru', 'Lungu', '.crm'), ['volunteer'], personType: 'volunteer', forPerson: self::person('petru'),
        )));
    }

    /**
     * A request from the public form that arrived today: enrolled automatically, still within the stage deadline,
     * and with no territory — so nobody is responsible yet.
     */
    private function todaysCandidate(): void
    {
        $this->as(null, fn () => app(RegisterCandidate::class)('Otilia', 'Demo-Negară', Personas::email('Otilia', 'Negara', '.crm'), null, 'website_form'));
    }

    private function segments(): void
    {
        $segments = app(Segments::class);

        $this->by('chisinau_head', fn (User $mihai) => $segments->save($mihai, [
            'name' => 'Susținători din Chișinău, peste 30 de ani',
            'description' => 'Pentru invitațiile la întâlnirile regionale.',
            'visibility' => 'shared',
            'criteria' => ['person_types' => ['supporter'], 'territory_id' => Personas::territory('chisinau')->id, 'age_from' => 30],
        ]));
        $this->by('hr', fn (User $natalia) => $segments->save($natalia, [
            'name' => 'În lucru în pâlnia „Aderare”',
            'visibility' => 'shared',
            'criteria' => ['pipeline_id' => self::pipeline(self::JOINING)->id, 'lead_status' => 'open'],
        ]));
        $this->by('branch_a_employee_1', fn (User $ion) => $segments->save($ion, [
            'name' => 'Au fost la întâlnirea din parc',
            'criteria' => ['interaction_kind' => 'event_visit', 'interaction_days' => 30],
        ]));
    }

    /**
     * Two imports (ТЗ §68): a file with errors and duplicates — refused as a whole, with a report; a clean file —
     * imported. And an export of leads by the head of the organization.
     */
    private function importsAndExport(): void
    {
        $this->by('hr', function (User $natalia): void {
            $import = app(PeopleImport::class);
            $import->upload($natalia, database_path('data/demo/people-import-sample.csv'), 'people-import-sample.csv', [
                'responsible_unit_id' => Personas::unit('branch_a')->id,
            ]);
            $clean = $import->upload($natalia, database_path('data/demo/people-import-clean.csv'), 'people-import-clean.csv', [
                'responsible_unit_id' => Personas::unit('north_south')->id,
            ]);
            // In the demo the import does not wait for a queue worker: the world must be complete when seeding ends.
            $import->commit($natalia, $clean, inline: true);
        });

        $this->by('org_head', fn (User $elena) => app(Exports::class)->request($elena, Exports::LEADS, 'xlsx', ['pipeline_id' => self::pipeline(self::JOINING)->id]));
    }
}
