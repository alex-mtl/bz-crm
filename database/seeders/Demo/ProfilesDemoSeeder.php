<?php

namespace Database\Seeders\Demo;

use App\Domain\Profiles\Actions\ManageConfidentialLayers;
use App\Domain\Profiles\Actions\ManageProfile;
use App\Domain\Profiles\Enums\Note360Type;
use App\Domain\Profiles\ProfileAccess;
use Illuminate\Database\Seeder;

/**
 * Profiles of the demo world (plan 2e): open profiles with all four visibility circles (Д-13), confidential layers
 * for people of both branches, "360" notes of both types (Д-14), and views of layers recorded in the journal.
 */
class ProfilesDemoSeeder extends Seeder
{
    use DemoSteps;

    public function run(): void
    {
        try {
            $this->at(60, fn () => $this->openProfiles());
            $this->at(45, fn () => $this->layers());
            $this->at(20, fn () => $this->notes360());
            $this->at(2, fn () => $this->views());
        } finally {
            $this->resetClock();
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $contacts  type, value, visibility
     * @param  array<string, mixed>  $profile
     */
    private function profile(string $key, array $contacts, array $profile = []): void
    {
        $user = Personas::user($key);
        $this->as($user, fn () => app(ManageProfile::class)->update($user, $user->person, $profile,
            array_map(fn (array $c): array => ['contact_type' => $c[0], 'value' => $c[1], 'visibility' => $c[2]], $contacts)));
    }

    private function openProfiles(): void
    {
        // Ion: a phone open to everyone — but the volunteer role has no right to the contacts group (Д-13: admin wins).
        $this->profile('branch_a_employee_1', [
            ['phone', '+373 69 100 001', 'all'],
            ['telegram', '@ion_botnaru_demo', 'colleagues'],
        ], ['bio' => 'Activist din sectorul Centru. Mă ocup de lucrul cu alegătorii.', 'cover_code' => 'amber',
            'skills' => ['comunicare', 'organizare evenimente'], 'languages' => ['română', 'rusă'], 'skills_visibility' => 'all']);
        // Maria: phone to the region (sector Centru), e-mail to management only.
        $this->profile('branch_a_employee_2', [
            ['phone', '+373 69 100 002', 'region'],
            ['email', 'maria.cebotari.personal@demo.bz-crm.test', 'management'],
        ], ['bio' => 'Activist senior, mentorul echipei.', 'birth_date' => '1991-04-12', 'gender' => 'female', 'personal_visibility' => 'colleagues']);
        $this->profile('branch_a_employee_3', [
            ['phone', '+373 69 100 003', 'colleagues'],
            ['telegram', '@sergiu_popa_demo', 'all'],
        ], ['skills' => ['logistică', 'transport'], 'skills_visibility' => 'region']);
        // Olga (branch B, sector Botanica): phone to the region — seen by Sergiu, who has a Botanica grant.
        $this->profile('branch_b_employee_1', [
            ['phone', '+373 69 200 001', 'region'],
            ['viber', '+373 69 200 001', 'colleagues'],
        ], ['bio' => 'Активистка сектора Ботаника.', 'languages' => ['русский', 'румынский', 'английский']]);
        // Diana (central office, no territories): "region" behaves as "colleagues" (Д-13).
        $this->profile('central_employee', [
            ['phone', '+373 69 300 001', 'region'],
        ], ['bio' => 'Specialist în aparatul central.']);
        $this->profile('branch_a_head', [
            ['phone', '+373 69 100 000', 'all'],
            ['email', 'ana.lungu@demo.bz-crm.test', 'all'],
        ], ['cover_code' => 'forest']);
    }

    private function layers(): void
    {
        $security = Personas::user('security');
        $hr = Personas::user('hr');
        $psy = Personas::user('psychologist');
        $layers = app(ManageConfidentialLayers::class);

        foreach (['branch_a_employee_1', 'branch_b_employee_1', 'branch_a_employee_2'] as $key) {
            $person = Personas::user($key)->person;
            $this->as($security, fn () => $layers->saveInternal($security, $person, [
                'home_address' => 'str. Demo '.$person->id.', Chișinău',
                'personal_phone' => '+373 79 '.str_pad((string) $person->id, 6, '0', STR_PAD_LEFT),
                'emergency_contacts' => [['name' => 'Rudă '.$person->last_name, 'relation' => 'soț / soție', 'phone' => '+373 79 000 000']],
            ]));
        }
        foreach (['branch_a_employee_1' => 4, 'branch_a_employee_2' => 5, 'branch_b_employee_1' => 3, 'branch_b_employee_2' => 4] as $key => $rating) {
            $this->as($hr, fn () => $layers->addHrAssessment($hr, Personas::user($key)->person, [
                'rating' => $rating, 'potential' => $rating >= 5 ? 'high' : 'medium',
                'strengths' => 'Responsabil(ă), lucrează bine cu oamenii.',
                'development' => 'Planificarea timpului.',
                'recommendations' => 'Curs de management de proiect.',
            ]));
        }
        $this->as($psy, fn () => $layers->addPsychologyNote($psy, Personas::user('branch_a_employee_3')->person,
            'Stres ridicat în perioada campaniei; recomand o discuție de susținere.'));
        $this->as($security, fn () => $layers->addSecurityNote($security, Personas::user('branch_b_head')->person,
            'Serie de încercări eșuate de autentificare de pe un IP străin — contul a fost verificat.'));
        $this->as($security, fn () => $layers->addSecurityNote($security, Personas::user('deactivated')->person,
            'Acces retras la cererea HR.'));
    }

    private function notes360(): void
    {
        $layers = app(ManageConfidentialLayers::class);
        $note = function (string $author, string $about, Note360Type $type, string $body) use ($layers) {
            $user = Personas::user($author);

            return $this->as($user, fn () => $layers->addNote360($user, Personas::user($about)->person, $type, $body));
        };

        // Feedback between colleagues of branch A.
        $feedback = $note('branch_a_employee_2', 'branch_a_employee_1', Note360Type::Feedback, 'Ion ține bine legătura cu alegătorii.');
        $note('branch_a_employee_1', 'branch_a_employee_3', Note360Type::Feedback, 'Sergiu organizează rapid transportul.');
        $note('branch_a_employee_3', 'branch_a_employee_2', Note360Type::Feedback, 'Maria ajută mereu echipa.');
        // Personal working notes.
        $note('branch_a_employee_3', 'branch_a_employee_1', Note360Type::Personal, 'De discutat cu Ion împărțirea sectoarelor.');
        // A personal note about one's own manager: Ana never sees it (Д-14), though she is in Ion's chain.
        $note('branch_a_employee_1', 'branch_a_head', Note360Type::Personal, 'Aș vrea mai mult feedback la ședințe.');
        // Edited note: the edit is journaled, the content is not.
        $maria = Personas::user('branch_a_employee_2');
        $this->at(18, fn () => $this->as($maria, fn () => $layers->editNote360($maria, $feedback,
            'Ion ține bine legătura cu alegătorii și răspunde repede la mesaje.')));
    }

    /**
     * Confidential layers were looked at — the journal shows who, whose and when (ФО §6.3.4).
     */
    private function views(): void
    {
        $access = app(ProfileAccess::class);
        foreach ([['hr', 'branch_a_employee_1', 'hr'], ['org_head', 'branch_b_head', 'security'], ['branch_a_head', 'branch_a_employee_3', 'internal']] as [$viewer, $about, $layer]) {
            $user = Personas::user($viewer);
            $this->as($user, fn () => $access->recordView(Personas::user($about)->person, $layer));
        }
    }
}
