<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call(LocalAdminSeeder::class);
        }

        if (! app()->isProduction() && app()->environment((array) config('demo.environments'))) {
            $this->call(DemoSeeder::class);
        }
    }
}
