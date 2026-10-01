<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\AccessPermissions;
use App\Domain\Access\Enums\SystemRole;
use App\Domain\Access\PermissionDefinition;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Audit\AuditPermissions;
use App\Domain\Catalogs\CatalogDefinition;
use App\Domain\Catalogs\CatalogPermissions;
use App\Domain\Catalogs\CatalogRegistry;
use App\Domain\CRM\CrmCatalogs;
use App\Domain\CRM\CrmPermissions;
use App\Domain\CustomObjects\CustomObjectPermissions;
use App\Domain\Events\EventCatalogs;
use App\Domain\Events\EventPermissions;
use App\Domain\Geo\GeoCatalogs;
use App\Domain\Geo\GeoPermissions;
use App\Domain\Groups\GroupPermissions;
use App\Domain\Identity\IdentityPermissions;
use App\Domain\Notifications\NotificationPermissions;
use App\Domain\Organization\OrganizationPermissions;
use App\Domain\People\PeopleCatalogs;
use App\Domain\People\PeoplePermissions;
use App\Domain\Projects\ProjectPermissions;
use App\Domain\Social\SocialCatalogs;
use App\Domain\Social\SocialPermissions;
use App\Domain\Tasks\TaskCatalogs;
use App\Domain\Tasks\TaskPermissions;
use Illuminate\Support\ServiceProvider;

/**
 * Collects what each module declares — permission codes (Д-17) and catalogs (Д-16) — at application
 * level, so modules do not have to depend on the Access / Catalogs modules just to declare them.
 */
final class ModuleDeclarationsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, string> permission list class => module code */
    private const array PERMISSIONS = [
        AuditPermissions::class => 'audit',
        AccessPermissions::class => 'access',
        IdentityPermissions::class => 'identity',
        PeoplePermissions::class => 'people',
        CatalogPermissions::class => 'catalogs',
        GeoPermissions::class => 'geo',
        OrganizationPermissions::class => 'organization',
        ProjectPermissions::class => 'projects',
        TaskPermissions::class => 'tasks',
        CrmPermissions::class => 'crm',
        CustomObjectPermissions::class => 'custom_objects',
        GroupPermissions::class => 'groups',
        SocialPermissions::class => 'social',
        EventPermissions::class => 'events',
        NotificationPermissions::class => 'notifications',
    ];

    /** @var array<class-string, string> catalog list class => module code */
    private const array CATALOGS = [
        GeoCatalogs::class => 'geo',
        PeopleCatalogs::class => 'people',
        TaskCatalogs::class => 'tasks',
        CrmCatalogs::class => 'crm',
        SocialCatalogs::class => 'social',
        EventCatalogs::class => 'events',
    ];

    public function boot(PermissionRegistry $permissions, CatalogRegistry $catalogs): void
    {
        foreach (self::PERMISSIONS as $class => $module) {
            foreach ($class::definitions() as $definition) {
                [$roles, $dataScopes] = self::parseRoles($definition['roles'] ?? []);
                $permissions->register(new PermissionDefinition(
                    $definition['code'],
                    $module,
                    $roles,
                    $definition['reserved'] ?? false,
                    $dataScopes,
                ));
            }
        }

        $permissions->register(
            new PermissionDefinition('system.status.read', 'platform', ['super_admin']),
            new PermissionDefinition('system.settings.manage', 'platform', ['super_admin']),
        );

        $this->registerCatalogs($catalogs);
    }

    /**
     * "employee:related" → role employee with the "related" data layer; "*" → every starter role.
     *
     * @param  list<string>  $declared
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private static function parseRoles(array $declared): array
    {
        $roles = [];
        $scopes = [];
        foreach ($declared as $entry) {
            [$role, $scope] = array_pad(explode(':', $entry, 2), 2, null);
            $expanded = $role === '*' ? array_map(fn (SystemRole $r): string => $r->value, SystemRole::cases()) : [$role];
            foreach ($expanded as $code) {
                $roles[] = $code;
                if ($scope !== null) {
                    $scopes[$code] = $scope;
                }
            }
        }

        return [array_values(array_unique($roles)), $scopes];
    }

    private function registerCatalogs(CatalogRegistry $catalogs): void
    {
        foreach (self::CATALOGS as $class => $module) {
            foreach ($class::definitions() as $definition) {
                $catalogs->register(new CatalogDefinition(
                    $definition['code'],
                    $module,
                    $definition['properties'] ?? [],
                    $definition['data'] ?? null,
                ));
            }
        }
    }
}
