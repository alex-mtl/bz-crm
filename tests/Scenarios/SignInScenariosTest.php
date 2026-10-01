<?php

use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\AuthProvider;
use App\Domain\Identity\Models\User;
use Database\Seeders\Demo\Personas;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\FakeOAuthGateway;

/*
 * IMPLEMENTATION-PLAN 1.4 — sign-in and registration, on the demo world (docs/demo/README.md § Вход).
 */

function applicant(string $first, string $last): User
{
    return User::query()->where('email', Personas::email($first, $last))->firstOrFail();
}

function signInWithPassword(string $email): Testable
{
    return Livewire::test(Login::class)
        ->fillForm(['email' => $email, 'password' => config('demo.password')])
        ->call('authenticate');
}

it('lets the pending applicant see only the status page', function () {
    $gheorghe = applicant(...Personas::PENDING_APPLICANT);

    signInWithPassword($gheorghe->email)->assertHasNoFormErrors();
    expect(auth()->id())->toBe($gheorghe->id);

    foreach (['/admin', '/admin/users', '/admin/journal', '/admin/profile', '/admin/catalogs'] as $url) {
        $this->get($url)->assertRedirect(route('account.status'));
    }
    $this->get(route('account.status'))->assertOk()
        ->assertSee('data-test="application-pending"', escape: false)
        ->assertSee(config('app.party_site_url'));
});

it('shows the rejected applicant the reason', function () {
    $svetlana = applicant(...Personas::REJECTED_APPLICANT);

    $this->actingAs($svetlana)->get(route('account.status'))
        ->assertSee('data-test="application-rejected"', escape: false)
        ->assertSee('date de contact verificabile');
});

it('does not let the deactivated user in, and keeps their history', function () {
    $igor = Personas::user('deactivated');

    signInWithPassword($igor->email)->assertHasFormErrors(['email']);
    $this->assertGuest();

    expect($igor->status)->toBe(UserStatus::Deactivated)
        ->and($igor->person)->not->toBeNull()
        ->and(JournalEntry::query()->where('subject_id', (string) $igor->id)->where('event_type', 'admission.invitation.accepted')->exists()
            || JournalEntry::query()->where('subject_id', (string) $igor->id)->where('event_type', 'identity.user.deactivated')->exists())->toBeTrue();
});

it('asks the security officer for the second factor', function () {
    $security = Personas::user('security');

    signInWithPassword($security->email)->assertHasNoFormErrors();
    $this->assertGuest();

    $code = app(AppAuthentication::class)->getCurrentCode($security, (string) config('demo.totp_secret'));
    Livewire::test(Login::class)
        ->fillForm(['email' => $security->email, 'password' => config('demo.password')])
        ->call('authenticate')
        ->fillForm(['app' => ['code' => $code]], 'multiFactorChallengeForm')
        ->call('authenticate');

    expect(auth()->id())->toBe($security->id);
});

it('signs Marin in through Google (a fictitious provider id)', function () {
    AuthProvider::query()->where('code', 'google')->update(['client_id' => 'demo', 'client_secret' => encrypt('demo', false), 'is_enabled' => true]);
    FakeOAuthGateway::install()->returns('demo-google-100001', 'marin.dogaru@gmail.example');

    $this->get(route('oauth.callback', 'google'))->assertRedirect('/admin');

    expect(auth()->id())->toBe(Personas::user('google_user')->id);
});

it('has the sign-in history the security service needs', function () {
    expect(journalCount('auth.login.succeeded'))->toBeGreaterThan(0)
        ->and(journalCount('auth.login.failed'))->toBeGreaterThan(1)
        ->and(journalCount('auth.login.failed_series'))->toBe(1)
        ->and(journalCount('auth.login.new_device'))->toBe(1);

    $this->actingAs(Personas::user('security'))
        ->get('/admin/users/'.Personas::user('branch_b_head')->id)
        ->assertOk()
        ->assertSee('203.0.113.77');
});

it('offers quick sign-in as a persona in demo environments only (Д-5)', function () {
    $this->get('/admin/login')->assertSee('data-test="demo-sign-in"', escape: false);

    $hr = Personas::user('hr');
    $this->post(route('demo.sign-in', $hr))->assertRedirect('/admin');
    expect(auth()->id())->toBe($hr->id)
        ->and(journalCount('auth.demo_login'))->toBeGreaterThan(0);

    auth()->logout();
    $this->flushSession();
    app()->detectEnvironment(fn () => 'production');

    $this->get('/admin/login')->assertDontSee('data-test="demo-sign-in"', escape: false);
    // Outside the testing environment CSRF is enforced; the point here is the 404, not the token.
    $this->withoutMiddleware(PreventRequestForgery::class)->post(route('demo.sign-in', $hr))->assertNotFound();
    $this->assertGuest();
});

it('refuses quick sign-in for accounts outside the demo domain and for the deactivated persona', function () {
    $this->post(route('demo.sign-in', User::query()->where('email', config('seed.admin_email'))->sole()))->assertRedirect(route('filament.admin.auth.login'));
    $this->post(route('demo.sign-in', Personas::user('deactivated')))->assertSessionHasErrors('oauth');
    $this->assertGuest();
});
