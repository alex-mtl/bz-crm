<?php

namespace Database\Seeders;

use App\Domain\Access\Actions\GrantInitialSuperAdmin;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use Illuminate\Database\Seeder;

class LocalAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('seed.admin_email');

        $user = User::query()->where('email', $email)->first();
        $person = $user !== null ? $user->person : Person::query()->create([
            'first_name' => 'Local',
            'last_name' => 'Admin',
            'email' => $email,
            'person_type' => 'employee',
        ]);

        $admin = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'person_id' => $person->id,
                'password' => config('seed.admin_password'),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        app(GrantInitialSuperAdmin::class)($admin);
    }
}
