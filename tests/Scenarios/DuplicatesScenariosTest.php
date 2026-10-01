<?php

use App\Domain\Identity\Actions\LinkAccounts;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\AuthProvider;
use App\Domain\Identity\Models\SocialIdentity;
use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\LinkHintStatus;
use App\Domain\People\Models\AccountLinkHint;
use App\Domain\People\Models\Person;
use App\Filament\Resources\LinkHints\Pages\ListAccountLinkHints;
use Database\Seeders\Demo\Personas;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Support\FakeOAuthGateway;

/*
 * IMPLEMENTATION-PLAN 1.4 — possible duplicates (Д-10), on the demo world (docs/demo/README.md § Дубли).
 */

function mariasGoogleAccount(): User
{
    return SocialIdentity::query()->where('provider_user_id', 'demo-google-100002')->sole()->user;
}

it('does not attach Maria\'s Google registration to her card and gives it no access', function () {
    $maria = Personas::user('branch_a_employee_2');
    $newAccount = mariasGoogleAccount();

    expect($newAccount->id)->not->toBe($maria->id)
        ->and($newAccount->person_id)->not->toBe($maria->person_id)
        ->and($newAccount->status)->toBe(UserStatus::PendingApproval)
        ->and($newAccount->email)->toBeNull();

    $this->actingAs($newAccount)->get('/admin/users/'.$maria->id)->assertRedirect(route('account.status'));
});

it('shows the reviewer the hint and why it was raised', function () {
    $hint = AccountLinkHint::query()->where('status', LinkHintStatus::Open)->sole();
    expect($hint->new_person_id)->toBe(mariasGoogleAccount()->person_id)
        ->and($hint->existing_person_id)->toBe(Personas::user('branch_a_employee_2')->person_id)
        ->and($hint->reasons)->toContain('email');

    $this->actingAs(Personas::user('hr'));
    Livewire::test(ListAccountLinkHints::class)->assertCanSeeTableRecords([$hint]);
});

it('does not let anyone without users.link_accounts link the accounts', function () {
    $hint = AccountLinkHint::query()->where('status', LinkHintStatus::Open)->sole();

    expect(fn () => app(LinkAccounts::class)(Personas::user('branch_a_head'), $hint))->toThrow(AuthorizationException::class);
    // Phase 2 (Д-10): Maria's manager sees the hint about her — but linking still needs users.link_accounts.
    $this->actingAs(Personas::user('branch_a_head'));
    Livewire::test(ListAccountLinkHints::class)
        ->assertCanSeeTableRecords([$hint])
        ->assertTableActionHidden('link', $hint);
});

it('lets Sergiu sign in both ways into one account after the manual link', function () {
    $sergiu = Personas::user('branch_a_employee_3');
    $facebook = SocialIdentity::query()->where('provider_user_id', 'demo-facebook-200001')->sole();
    $duplicateCard = Person::query()->where('duplicate_of_person_id', $sergiu->person_id)->sole();

    expect($facebook->user_id)->toBe($sergiu->id)
        ->and($duplicateCard->user->status)->toBe(UserStatus::Deactivated)
        ->and(journalCount('identity.accounts.linked'))->toBe(1);

    AuthProvider::query()->where('code', 'facebook')->update(['client_id' => 'demo', 'client_secret' => encrypt('demo', false), 'is_enabled' => true]);
    FakeOAuthGateway::install()->returns('demo-facebook-200001', null, provider: 'facebook');
    $this->get(route('oauth.callback', 'facebook'))->assertRedirect('/admin');
    expect(auth()->id())->toBe($sergiu->id);
});

it('does not show the dismissed namesake hint again', function () {
    $dismissed = AccountLinkHint::query()->where('status', LinkHintStatus::Dismissed)->sole();

    $this->actingAs(Personas::user('hr'));
    Livewire::test(ListAccountLinkHints::class)->assertCanNotSeeTableRecords([$dismissed]);
    expect(Person::query()->where('first_name', 'Ion')->where('last_name', 'Botnaru')->count())->toBe(2);
});
