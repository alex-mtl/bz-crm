<?php

namespace Database\Seeders;

use App\Domain\Access\Actions\SyncSystemRoles;
use App\Domain\Audit\JournalContext;
use App\Domain\Catalogs\Actions\ImportReferenceCatalogs;
use App\Domain\Geo\Actions\ImportTerritories;
use App\Domain\Identity\Actions\EnsureStarterAuthProviders;
use App\Domain\Notifications\SyncNotificationDefaults;
use App\Domain\Tasks\TaskWorkflow;
use Illuminate\Database\Seeder;

/**
 * Real reference data for every environment, production included (unlike the demo world).
 * Idempotent: safe to run on every deploy.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        app(JournalContext::class)->asSystem('seeder:reference-data')->startCorrelation();

        app(SyncSystemRoles::class)();
        app(ImportReferenceCatalogs::class)();
        app(EnsureStarterAuthProviders::class)();
        app(ImportTerritories::class)();
        TaskWorkflow::ensureDefaults();
        app(SyncNotificationDefaults::class)();
    }
}
