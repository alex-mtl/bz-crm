<?php

use App\Domain\Access\AccessServiceProvider;
use App\Domain\Audit\AuditServiceProvider;
use App\Domain\Catalogs\CatalogsServiceProvider;
use App\Domain\CRM\CrmServiceProvider;
use App\Domain\CustomObjects\CustomObjectsServiceProvider;
use App\Domain\Events\EventsServiceProvider;
use App\Domain\Geo\GeoServiceProvider;
use App\Domain\Groups\GroupsServiceProvider;
use App\Domain\Identity\IdentityServiceProvider;
use App\Domain\Messaging\MessagingServiceProvider;
use App\Domain\Notifications\NotificationsServiceProvider;
use App\Domain\Organization\OrganizationServiceProvider;
use App\Domain\People\PeopleServiceProvider;
use App\Domain\Profiles\ProfilesServiceProvider;
use App\Domain\Projects\ProjectsServiceProvider;
use App\Domain\Social\SocialServiceProvider;
use App\Domain\Tasks\TasksServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\ModuleDeclarationsServiceProvider;

return [
    AppServiceProvider::class,
    AuditServiceProvider::class,
    AccessServiceProvider::class,
    NotificationsServiceProvider::class,
    CatalogsServiceProvider::class,
    GeoServiceProvider::class,
    OrganizationServiceProvider::class,
    IdentityServiceProvider::class,
    PeopleServiceProvider::class,
    ProfilesServiceProvider::class,
    MessagingServiceProvider::class,
    TasksServiceProvider::class,
    ProjectsServiceProvider::class,
    CustomObjectsServiceProvider::class,
    CrmServiceProvider::class,
    GroupsServiceProvider::class,
    SocialServiceProvider::class,
    EventsServiceProvider::class,
    ModuleDeclarationsServiceProvider::class,
    AdminPanelProvider::class,
];
