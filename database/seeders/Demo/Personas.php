<?php

namespace Database\Seeders\Demo;

use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\People\Models\Person;
use Illuminate\Support\Str;

/**
 * The demo world's cast (IMPLEMENTATION-PLAN "Каркас демо-мира", docs/demo/README.md).
 * All people are fictional. Phase 2 places them into units and territories — the keys stay stable.
 */
final class Personas
{
    /**
     * key => [first name, last name, roles, person type]
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>, 3: string}>
     */
    public const array INVITED = [
        'org_head' => ['Elena', 'Munteanu', ['org_head'], 'employee'],
        'chisinau_head' => ['Mihai', 'Rotaru', ['unit_head'], 'employee'],
        'branch_a_head' => ['Ana', 'Lungu', ['unit_head'], 'employee'],
        'branch_a_employee_1' => ['Ion', 'Botnaru', ['employee'], 'employee'],
        'branch_a_employee_2' => ['Maria', 'Cebotari', ['employee'], 'employee'],
        'branch_a_employee_3' => ['Sergiu', 'Popa', ['employee'], 'employee'],
        'branch_b_head' => ['Pavel', 'Ursu', ['unit_head'], 'employee'],
        'branch_b_employee_1' => ['Olga', 'Sârbu', ['employee'], 'employee'],
        'branch_b_employee_2' => ['Dan', 'Țurcanu', ['employee'], 'employee'],
        'branch_b_employee_3' => ['Irina', 'Moraru', ['employee'], 'employee'],
        'branch_b_acting_head' => ['Veronica', 'Stratan', ['employee'], 'employee'],
        'balti_head' => ['Nicolae', 'Grosu', ['unit_head'], 'employee'],
        'balti_employee_1' => ['Tatiana', 'Bivol', ['employee'], 'employee'],
        'balti_employee_2' => ['Vasile', 'Cojocaru', ['employee'], 'employee'],
        'north_south_employee' => ['Andrian', 'Vlas', ['employee'], 'employee'],
        'central_employee' => ['Diana', 'Popescu', ['employee'], 'employee'],
        'hr' => ['Natalia', 'Rusu', ['hr'], 'employee'],
        'security' => ['Alexandru', 'Guțu', ['security'], 'employee'],
        'psychologist' => ['Cristina', 'Melnic', ['psychologist'], 'employee'],
        'catalog_admin' => ['Liliana', 'Negru', ['catalog_admin'], 'employee'],
        'inbox_operator' => ['Stela', 'Rotari', ['inbox_operator'], 'employee'],
        'moderator' => ['Eugen', 'Frunză', ['moderator'], 'employee'],
        'volunteer' => ['Radu', 'Ceban', ['volunteer'], 'volunteer'],
        'google_user' => ['Marin', 'Dogaru', ['employee'], 'employee'],
        'deactivated' => ['Igor', 'Balan', ['employee'], 'employee'],
    ];

    /**
     * Units of the demo world (plan "Каркас демо-мира"): key => [name, parent key, territory codes].
     *
     * @var array<string, array{0: string, 1: string|null, 2: list<string>}>
     */
    public const array UNITS = [
        'root' => ['Organizația (demo)', null, ['md']],
        'central' => ['Aparatul central', 'root', []],
        'hr_dept' => ['Resurse umane', 'central', []],
        'communications' => ['Comunicare', 'central', []],
        'security_dept' => ['Serviciul de securitate', 'central', []],
        'north_south' => ['Administrația Nord–Sud', 'root', ['balti', 'cahul']],
        'chisinau' => ['Organizația regională Chișinău', 'root', ['chisinau']],
        'branch_a' => ['Filiala A (Centru)', 'chisinau', ['chisinau/sectorul-centru']],
        'branch_b' => ['Filiala B (Botanica)', 'chisinau', ['chisinau/sectorul-botanica']],
        'balti' => ['Organizația regională Bălți', 'root', ['balti']],
    ];

    /**
     * Who works where. The first persona of each unit listed as head becomes its head.
     *
     * @var array<string, array{unit: string, position: string, head?: bool}>
     */
    public const array PLACEMENT = [
        'org_head' => ['unit' => 'root', 'position' => 'Președinte', 'head' => true],
        'super_admin' => ['unit' => 'central', 'position' => 'Administrator de sistem', 'head' => true],
        'central_employee' => ['unit' => 'central', 'position' => 'Specialist'],
        'hr' => ['unit' => 'hr_dept', 'position' => 'Specialist HR', 'head' => true],
        'psychologist' => ['unit' => 'hr_dept', 'position' => 'Psiholog'],
        'catalog_admin' => ['unit' => 'hr_dept', 'position' => 'Administrator de nomenclatoare'],
        'inbox_operator' => ['unit' => 'communications', 'position' => 'Operator', 'head' => true],
        'moderator' => ['unit' => 'communications', 'position' => 'Moderator'],
        'security' => ['unit' => 'security_dept', 'position' => 'Ofițer de securitate', 'head' => true],
        'north_south_employee' => ['unit' => 'north_south', 'position' => 'Coordonator regional'],
        'chisinau_head' => ['unit' => 'chisinau', 'position' => 'Președintele organizației regionale', 'head' => true],
        'branch_a_head' => ['unit' => 'branch_a', 'position' => 'Șefa filialei', 'head' => true],
        'branch_a_employee_1' => ['unit' => 'branch_a', 'position' => 'Activist'],
        'branch_a_employee_2' => ['unit' => 'branch_a', 'position' => 'Activist senior'],
        'branch_a_employee_3' => ['unit' => 'branch_a', 'position' => 'Activist'],
        'volunteer' => ['unit' => 'branch_a', 'position' => 'Voluntar'],
        'google_user' => ['unit' => 'branch_a', 'position' => 'Activist'],
        'deactivated' => ['unit' => 'branch_a', 'position' => 'Activist'],
        'branch_b_head' => ['unit' => 'branch_b', 'position' => 'Șeful filialei', 'head' => true],
        'branch_b_employee_1' => ['unit' => 'branch_b', 'position' => 'Activistă'],
        'branch_b_employee_2' => ['unit' => 'branch_b', 'position' => 'Activist'],
        'branch_b_employee_3' => ['unit' => 'branch_b', 'position' => 'Activistă'],
        'branch_b_acting_head' => ['unit' => 'branch_b', 'position' => 'Adjunctă'],
        'balti_head' => ['unit' => 'balti', 'position' => 'Președintele organizației regionale', 'head' => true],
        // Tatiana starts in branch B and is transferred to Bălți later (plan 2e).
        'balti_employee_1' => ['unit' => 'branch_b', 'position' => 'Activistă'],
        'balti_employee_2' => ['unit' => 'balti', 'position' => 'Activist'],
    ];

    public static function unit(string $key): OrgUnit
    {
        return OrgUnit::query()->where('name', self::UNITS[$key][0])->firstOrFail();
    }

    public static function territory(string $code): Territory
    {
        return Territory::query()->where('code', $code)->firstOrFail();
    }

    public const array SUPER_ADMIN = ['Victor', 'Ciobanu'];

    public const array PENDING_APPLICANT = ['Gheorghe', 'Arama'];

    public const array REJECTED_APPLICANT = ['Svetlana', 'Chirica'];

    public const array NAMESAKE_APPLICANT = ['Ion', 'Botnaru'];

    public const array CANDIDATE = ['Lilia', 'Zaharia'];

    public static function email(string $firstName, string $lastName, string $suffix = ''): string
    {
        return Str::lower(Str::ascii($firstName.'.'.$lastName.$suffix)).'@'.config('demo.email_domain');
    }

    public static function emailOf(string $key): string
    {
        if ($key === 'super_admin') {
            return self::email(...self::SUPER_ADMIN);
        }
        [$first, $last] = self::INVITED[$key];

        return self::email($first, $last);
    }

    public static function user(string $key): User
    {
        return User::query()->where('email', self::emailOf($key))->firstOrFail();
    }

    public static function person(string $firstName, string $lastName): Person
    {
        return Person::query()->where('first_name', $firstName)->where('last_name', $lastName)->orderBy('id')->firstOrFail();
    }
}
