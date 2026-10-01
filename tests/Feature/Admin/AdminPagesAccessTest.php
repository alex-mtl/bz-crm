<?php

use App\Domain\Access\Actions\GrantInitialSuperAdmin;
use App\Domain\Access\Actions\SyncSystemRoles;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Catalogs\Actions\ImportReferenceCatalogs;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Journal\Pages\ListJournalEntries;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Users\Pages\ListUsers;
use Livewire\Livewire;

beforeEach(function () {
    app(SyncSystemRoles::class)();
    app(ImportReferenceCatalogs::class)();
});

function superAdmin(): User
{
    $user = User::factory()->create();
    app(GrantInitialSuperAdmin::class)($user);

    return $user;
}

dataset('admin pages', [
    'users' => ['/admin/users', 'users.read'],
    'applications' => ['/admin/applications', 'users.approve'],
    'invitations' => ['/admin/invitations', 'users.invite'],
    'new invitation' => ['/admin/invitations/create', 'users.invite'],
    'link hints' => ['/admin/link-hints', 'users.link_accounts'],
    'roles' => ['/admin/roles', 'roles.read'],
    'new role' => ['/admin/roles/create', 'roles.manage'],
    'auth providers' => ['/admin/auth-providers', 'auth.providers.manage'],
    'journal' => ['/admin/journal', 'audit.read'],
    'journal settings' => ['/admin/journal-settings', 'audit.settings.manage'],
    'catalogs' => ['/admin/catalogs', 'catalogs.read'],
    'new catalog item' => ['/admin/catalogs/create', 'catalogs.manage'],
    'catalog proposals' => ['/admin/catalog-proposals', 'catalogs.review'],
    'profile' => ['/admin/profile', null],
    'territories' => ['/admin/territories', 'territories.read'],
    'org units' => ['/admin/org-units', 'org_units.read'],
    'people' => ['/admin/people', 'people.read'],
    'tasks' => ['/admin/tasks', 'tasks.read'],
    'new task' => ['/admin/tasks/create', 'tasks.create'],
    'projects' => ['/admin/projects', 'projects.read'],
    'new project' => ['/admin/projects/create', 'projects.create'],
    'project templates' => ['/admin/project-templates', 'projects.templates.manage'],
    'task board' => ['/admin/task-board', 'tasks.read'],
    'task calendar' => ['/admin/task-calendar', 'tasks.read'],
    'gantt' => ['/admin/project-gantt', 'projects.read'],
    'access simulator' => ['/admin/access-simulator', 'access.simulate'],
    'work settings' => ['/admin/work-settings', 'tasks.workflow.manage'],
    'new person' => ['/admin/people/create', 'people.create'],
    'leads' => ['/admin/leads', 'pipelines.read'],
    'new lead' => ['/admin/leads/create', 'leads.create'],
    'lead board' => ['/admin/lead-board', 'pipelines.read'],
    'appeals' => ['/admin/appeals', 'appeals.read'],
    'new appeal' => ['/admin/appeals/create', 'appeals.create'],
    'segments' => ['/admin/segments', 'people.read'],
    'duplicates' => ['/admin/duplicates', 'crm.duplicates.review'],
    'imports' => ['/admin/imports', 'people.import'],
    'pipelines' => ['/admin/pipelines', 'pipelines.manage'],
    'custom fields' => ['/admin/custom-fields', 'custom_objects.types.manage'],
    'field rules' => ['/admin/field-rules', 'access.field_rules.manage'],
    'feed' => ['/admin/feed', 'posts.read'],
    'groups' => ['/admin/groups', 'groups.read'],
    'moderation queue' => ['/admin/moderation', 'moderation.queue.read'],
    'moderation actions' => ['/admin/moderation-actions', 'moderation.queue.read'],
    'events' => ['/admin/events', 'events.read'],
    'calendar' => ['/admin/calendar', 'events.read'],
    'notification center' => ['/admin/notification-center', 'notifications.read'],
    'notification settings' => ['/admin/notification-settings', 'notifications.preferences'],
    'notification defaults' => ['/admin/notification-defaults', 'notifications.defaults.manage'],
    'announcements' => ['/admin/announcements', 'notifications.broadcast'],
]);

it('opens every admin page for the super admin', function (string $url) {
    $this->actingAs(superAdmin())->get($url)->assertOk();
})->with('admin pages');

it('closes the page to an active user without the permission', function (string $url, ?string $code) {
    $response = $this->actingAs(userWithRoles())->get($url);

    $code === null ? $response->assertOk() : $response->assertForbidden();
})->with('admin pages');

it('opens a user card and an existing role in the constructor', function () {
    $admin = superAdmin();
    $role = Role::query()->where('code', 'hr')->sole();

    $this->actingAs($admin)->get('/admin/users/'.$admin->id)->assertOk();
    $this->actingAs($admin)->get('/admin/roles/'.$role->id.'/edit')->assertOk();
    $this->actingAs($admin)->get('/admin/catalogs/'.CatalogItem::query()->value('id').'/edit')->assertOk();
});

it('journals opening the journal and a user\'s sign-in history', function () {
    $admin = superAdmin();

    $this->actingAs($admin);
    Livewire::test(ListJournalEntries::class)->assertOk();
    $this->get('/admin/users/'.$admin->id)->assertOk();

    expect(JournalEntry::query()->where('event_type', 'audit.viewed')->count())->toBe(2);
});

it('saves a role through the constructor: permissions are allowed and denied per module', function () {
    $admin = superAdmin();
    $role = Role::query()->where('code', 'volunteer')->sole();
    $this->actingAs($admin);

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm([
            'allow' => ['catalogs' => ['catalogs.read']],
            'deny' => ['identity' => ['users.read']],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->permissions()->pluck('effect', 'permission_code')->map->value->all())
        ->toMatchArray(['catalogs.read' => 'allow', 'users.read' => 'deny'])
        ->and(journalCount('access.role.permissions_changed'))->toBeGreaterThan(0);
});

it('lists users for HR', function () {
    $hr = userWithRoles('hr');
    $other = User::factory()->create();

    $this->actingAs($hr);
    Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$other]);
});
