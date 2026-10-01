<?php

namespace Tests\Scenarios;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Scenario tests (Д-2) run against the real demo world: it is seeded once per test run into its own
 * database (bz_crm_scenarios), and every test runs inside a transaction that is rolled back.
 */
trait UsesDemoWorld
{
    protected function setUpUsesDemoWorld(): void
    {
        config(['database.connections.mysql.database' => 'bz_crm_scenarios']);
        DB::purge('mysql');

        if (! DemoWorldState::$seeded) {
            Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
            DemoWorldState::$seeded = true;
        }

        DB::connection('mysql')->beginTransaction();
    }

    protected function tearDownUsesDemoWorld(): void
    {
        DB::connection('mysql')->rollBack();
    }
}
