<?php

namespace Database\Seeders\Demo;

use App\Domain\Access\Actions\GrantInitialSuperAdmin;
use App\Domain\Access\Admission\AcceptInvitation;
use App\Domain\Access\Admission\DecideApplication;
use App\Domain\Access\Admission\InviteUser;
use App\Domain\Access\Admission\RevokeInvitation;
use App\Domain\Access\Exceptions\PermissionEscalation;
use App\Domain\Audit\Enums\ActingAs;
use App\Domain\Audit\JournalContext;
use App\Domain\Catalogs\Actions\ProposeCatalogItem;
use App\Domain\Catalogs\Actions\ReviewCatalogProposal;
use App\Domain\Identity\Actions\ConfirmEmail;
use App\Domain\Identity\Actions\DismissLinkHint;
use App\Domain\Identity\Actions\LinkAccounts;
use App\Domain\Identity\Actions\RegisterWithEmail;
use App\Domain\Identity\Actions\SignInWithProvider;
use App\Domain\Identity\Data\ExternalIdentity;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Impersonations;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\RegistrationApplication;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\RegisterCandidate;
use App\Domain\People\Enums\LinkHintStatus;
use App\Domain\People\Models\AccountLinkHint;
use App\Domain\People\Models\Person;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 1 of the demo world: accounts, admission paths, roles, sign-ins, possible duplicates (IMPLEMENTATION-PLAN 1.3).
 * Time is moved back so the journal tells the world's story in order; all dates are relative to "now".
 * Every step runs on behalf of the persona who would do it, so the journal shows the right actors.
 */
class IdentityDemoSeeder extends Seeder
{
    use DemoSteps;

    public function run(): void
    {
        try {
            $admin = $this->at(90, fn () => $this->bootstrapSuperAdmin());
            $this->at(89, fn () => $this->inviteTeam($admin));
            $this->at(85, fn () => $this->deniedEscalation());
            $this->at(40, fn () => $this->googleAndTwoFactor());
            $this->at(30, fn () => $this->linkedDuplicate());
            $this->at(20, fn () => $this->dismissedNamesake());
            $this->at(15, fn () => $this->rejectedApplicant());
            $this->at(10, fn () => $this->invitation('viitor', 'voluntar', ['volunteer'], $admin));
            $this->at(7, fn () => $this->revokedInvitation());
            $this->at(5, fn () => $this->openDuplicateHint());
            $this->at(3, fn () => $this->impersonation());
            $this->at(2, fn () => $this->pendingApplicant());
            $this->at(1, fn () => $this->invitation('nou', 'coleg', ['employee'], Personas::user('hr')));
            $this->at(1, fn () => $this->catalogProposals());
            $this->at(1, fn () => $this->as(null, fn () => app(RegisterCandidate::class)(
                'Lilia', 'Zaharia', Personas::email(...Personas::CANDIDATE), '+373 69 000 111', 'public_form',
            )));
            $this->signIns();
        } finally {
            $this->resetClock();
        }
    }

    private function password(): string
    {
        return (string) config('demo.password');
    }

    private function bootstrapSuperAdmin(): User
    {
        [$first, $last] = Personas::SUPER_ADMIN;
        $email = Personas::email($first, $last);
        $person = Person::query()->create(['first_name' => $first, 'last_name' => $last, 'email' => $email, 'person_type' => 'employee']);
        $user = User::query()->create([
            'person_id' => $person->id, 'email' => $email, 'password' => $this->password(),
            'status' => UserStatus::Active, 'email_verified_at' => now(), 'locale' => 'ro',
        ]);
        app(GrantInitialSuperAdmin::class)($user);

        return $user;
    }

    /**
     * @param  list<string>  $roles
     */
    private function invitation(string $first, string $last, array $roles, User $inviter): void
    {
        $this->as($inviter, fn () => app(InviteUser::class)($inviter, Personas::email($first, $last), $roles, ucfirst($first), ucfirst($last), 'employee', 7));
    }

    private function inviteTeam(User $admin): void
    {
        $russianSpeakers = ['branch_b_employee_1', 'branch_b_employee_2', 'balti_employee_2', 'psychologist'];
        $tokens = $this->as($admin, function () use ($admin): array {
            $tokens = [];
            foreach (Personas::INVITED as $key => [$first, $last, $roles, $type]) {
                $tokens[$key] = app(InviteUser::class)($admin, Personas::email($first, $last), $roles, $first, $last, $type, 14)['token'];
            }

            return $tokens;
        });

        Carbon::setTestNow(now()->addDay());
        $this->as(null, function () use ($tokens, $russianSpeakers): void {
            foreach (Personas::INVITED as $key => [$first, $last]) {
                $locale = in_array($key, $russianSpeakers, true) ? 'ru' : ($key === 'catalog_admin' ? 'en' : 'ro');
                app(AcceptInvitation::class)($tokens[$key], $first, $last, $this->password(), $locale);
            }
        });
    }

    /**
     * "Not more than you have" (Д-17): a branch head tries to invite a super admin — refused and journaled.
     */
    private function deniedEscalation(): void
    {
        $head = Personas::user('branch_a_head');
        $this->as($head, function () use ($head): void {
            try {
                app(InviteUser::class)($head, Personas::email('prieten', 'influent'), ['super_admin']);
            } catch (PermissionEscalation) {
                // Expected: the journal now holds access.escalation.denied.
            }
        });
    }

    private function googleAndTwoFactor(): void
    {
        $marin = Personas::user('google_user');
        $this->as($marin, fn () => app(SignInWithProvider::class)(
            new ExternalIdentity('google', 'demo-google-100001', $marin->email, true, 'Marin', 'Dogaru'), $marin,
        ));

        $security = Personas::user('security');
        $this->as($security, fn () => $security->saveAppAuthenticationSecret((string) config('demo.totp_secret')));
    }

    /**
     * Sergiu registered once more through Facebook; HR recognised him and linked the accounts (Д-10).
     */
    private function linkedDuplicate(): void
    {
        $sergiu = Personas::user('branch_a_employee_3');
        $duplicate = $this->as(null, fn () => app(SignInWithProvider::class)(
            new ExternalIdentity('facebook', 'demo-facebook-200001', 'sergiu.popa.personal@'.config('demo.email_domain'), true, 'Sergiu', 'Popa'),
        ));

        Carbon::setTestNow(now()->addDay());
        $hr = Personas::user('hr');
        $this->as($hr, fn () => app(LinkAccounts::class)($hr, $this->hintBetween($duplicate->person, $sergiu->person)));
    }

    /**
     * Two different people with the same name: the hint is dismissed, the newcomer becomes a volunteer.
     */
    private function dismissedNamesake(): void
    {
        [$first, $last] = Personas::NAMESAKE_APPLICANT;
        $namesake = $this->register($first, $last, Personas::email($first, $last, '.balti'), 'ro');

        Carbon::setTestNow(now()->addDay());
        $hr = Personas::user('hr');
        $this->as($hr, function () use ($hr, $namesake): void {
            app(DismissLinkHint::class)($hr, $this->hintBetween($namesake->person, Personas::user('branch_a_employee_1')->person));
            app(DecideApplication::class)->approve($hr, $this->applicationOf($namesake), ['volunteer'], 'volunteer');
        });
    }

    private function rejectedApplicant(): void
    {
        [$first, $last] = Personas::REJECTED_APPLICANT;
        $user = $this->register($first, $last, Personas::email($first, $last), 'ru');

        Carbon::setTestNow(now()->addDay());
        $hr = Personas::user('hr');
        $this->as($hr, fn () => app(DecideApplication::class)->reject($hr, $this->applicationOf($user),
            'Cererea nu conține date de contact verificabile. Vă rugăm să depuneți una nouă cu un număr de telefon.'));
    }

    private function pendingApplicant(): void
    {
        [$first, $last] = Personas::PENDING_APPLICANT;
        $this->register($first, $last, Personas::email($first, $last), 'ro');
    }

    /**
     * Self-registration by e-mail, then the applicant follows the link in the confirmation e-mail.
     */
    private function register(string $first, string $last, string $email, string $locale): User
    {
        $user = $this->as(null, fn () => app(RegisterWithEmail::class)($first, $last, $email, $this->password(), $locale));
        $this->as($user, fn () => app(ConfirmEmail::class)($user));

        return $user;
    }

    /**
     * Д-19: the super admin signed in as Ion for 12 minutes to check what he sees; Ion was notified.
     */
    private function impersonation(): void
    {
        $admin = Personas::user('super_admin');
        $impersonations = app(Impersonations::class);

        $this->as($admin, function () use ($admin, $impersonations): void {
            $impersonation = $impersonations->begin($admin, Personas::user('branch_a_employee_1'),
                'Ion nu vede sarcinile filialei în listă — verificare la cererea lui.');
            Carbon::setTestNow(now()->addMinutes(12));
            $impersonations->applyToContext($impersonation);
            $impersonations->end($impersonation);
        });

        $context = app(JournalContext::class);
        $context->actingAs = ActingAs::Own;
        $context->actingAsRef = null;
        unset($context->extra['on_behalf_of_user_id']);
    }

    private function revokedInvitation(): void
    {
        $hr = Personas::user('hr');
        $this->invitation('invitatie', 'revocata', ['employee'], $hr);
        $invitation = Invitation::query()->where('email', Personas::email('invitatie', 'revocata'))->sole();
        $this->as($hr, fn () => app(RevokeInvitation::class)($hr, $invitation));
    }

    /**
     * Maria (invited long ago) signs in with Google using the same e-mail: a NEW application appears,
     * with an open "possibly the same person" hint for the reviewer (Д-10).
     */
    private function openDuplicateHint(): void
    {
        $maria = Personas::user('branch_a_employee_2');
        $this->as(null, fn () => app(SignInWithProvider::class)(
            new ExternalIdentity('google', 'demo-google-100002', $maria->email, true, 'Maria', 'Cebotari'),
        ));
    }

    private function catalogProposals(): void
    {
        $branchHead = Personas::user('branch_a_head');
        $this->as($branchHead, fn () => app(ProposeCatalogItem::class)($branchHead, 'task_types', ['ro' => 'Distribuirea ziarului local'], 'ro',
            'Filiala distribuie lunar ziarul local; vrem să urmărim aceste sarcini separat de flyere.'));

        $regionHead = Personas::user('chisinau_head');
        $rejected = $this->as($regionHead, fn () => app(ProposeCatalogItem::class)($regionHead, 'task_types', ['ru' => 'Звонки избирателям'], 'ru',
            'Нужен отдельный тип для обзвона.'));

        $catalogAdmin = Personas::user('catalog_admin');
        $this->as($catalogAdmin, fn () => app(ReviewCatalogProposal::class)($catalogAdmin, $rejected, false,
            'Такой тип уже есть: «Звонки» — используйте его.'));
    }

    /**
     * Sign-in history for the security service: ordinary sign-ins from a couple of devices,
     * one mistyped password and one series of failed attempts (T3).
     */
    private function signIns(): void
    {
        $context = app(JournalContext::class);
        $devices = [
            ['10.20.0.11', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36'],
            ['10.20.0.57', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1'],
        ];
        $signIn = function (User $user, int $device) use ($context, $devices): void {
            [$context->ipAddress, $context->userAgent] = $devices[$device];
            $this->as($user, function () use ($user): void {
                event(new Login('web', $user, false));
                event(new Logout('web', $user));
            });
        };

        foreach (['org_head', 'chisinau_head', 'branch_a_head', 'hr', 'security', 'volunteer'] as $i => $key) {
            $this->at(6 - $i % 3, fn () => $signIn(Personas::user($key), 0));
        }
        // The org head also signs in from a phone: a "new device" alert.
        $this->at(1, fn () => $signIn(Personas::user('org_head'), 1));

        $this->at(1, function () use ($context, $devices): void {
            [$context->ipAddress, $context->userAgent] = $devices[0];
            $olga = Personas::user('branch_b_employee_1');
            $this->as(null, fn () => event(new Failed('web', $olga, ['email' => $olga->email])));
        });

        $this->at(0, function () use ($context): void {
            [$context->ipAddress, $context->userAgent] = ['203.0.113.77', 'python-requests/2.32'];
            $pavel = Personas::user('branch_b_head');
            // The failure counter lives in the cache in real time; start this demo series from zero.
            Cache::forget('auth-failures:'.hash('sha256', (string) $pavel->email));
            $this->as(null, function () use ($pavel): void {
                for ($i = 0; $i < 5; $i++) {
                    event(new Failed('web', $pavel, ['email' => $pavel->email]));
                }
            });
        });

        [$context->ipAddress, $context->userAgent] = [null, null];
    }

    private function applicationOf(User $user): RegistrationApplication
    {
        return RegistrationApplication::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function hintBetween(Person $new, Person $existing): AccountLinkHint
    {
        return AccountLinkHint::query()
            ->where('new_person_id', $new->id)->where('existing_person_id', $existing->id)
            ->where('status', LinkHintStatus::Open)->firstOrFail();
    }
}
