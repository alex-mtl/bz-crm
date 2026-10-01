<?php

use App\Domain\Identity\Models\User;
use App\Filament\Pages\SystemStatus;
use Database\Seeders\LocalAdminSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;

it('redirects the root url to the admin panel', function () {
    $this->get('/')->assertRedirect('/admin');
});

it('redirects guests from the panel to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('renders the login page in the default romanian locale', function () {
    expect(app()->getLocale())->toBe('ro');

    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('lang="ro"', escape: false);
});

it('lets an active user open the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertOk();
});

it('sends applicants from the panel to their account status page', function (string $state) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get('/admin')
        ->assertRedirect(route('account.status'));
})->with(['pending', 'rejected']);

it('sends a user with an unconfirmed e-mail to the account status page', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/admin')
        ->assertRedirect(route('account.status'));
});

it('denies panel access to a deactivated account', function () {
    $this->actingAs(User::factory()->deactivated()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('hides the system status page from users without system.status.read', function () {
    $this->actingAs(userWithRoles('employee'))
        ->get('/admin/system-status')
        ->assertForbidden();
});

it('shows the system status page with every check', function () {
    $this->actingAs(userWithRoles('super_admin'));

    Livewire::test(SystemStatus::class)
        ->assertOk()
        ->assertSet('checks', ['database' => true, 'redis' => true, 'cache' => true, 'storage' => true])
        ->assertSee(__('system_status.check.database'))
        ->call('refreshChecks')
        ->assertSet('failedJobs', 0);
});

it('seeds the local admin account idempotently', function () {
    $this->seed([ReferenceDataSeeder::class, LocalAdminSeeder::class]);
    $this->seed([ReferenceDataSeeder::class, LocalAdminSeeder::class]);

    $admin = User::query()->where('email', config('seed.admin_email'))->sole();
    expect($admin->can('roles.manage'))->toBeTrue();
});
