<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Volume profile for load tests (ФО §3.4, ТЗ §53): thousands of people, later tens of thousands of tasks
 * and messages. Unlike the demo world it bulk-inserts (speed over history) and is run only on demand:
 *
 *   php artisan db:seed --class=VolumeSeeder
 *
 * Each module adds its own block here as it appears. Never runs in production.
 */
class VolumeSeeder extends Seeder
{
    public const int PEOPLE = 5000;

    private const int CHUNK = 500;

    public function run(): void
    {
        DemoSeeder::ensureAllowed();
        fake()->seed(20260930);

        $this->people(self::PEOPLE);
    }

    /**
     * People with accounts: 90 % active, the rest spread over the other statuses.
     */
    private function people(int $count): void
    {
        $password = Hash::make((string) config('demo.password'));
        $statuses = [UserStatus::PendingApproval, UserStatus::Rejected, UserStatus::Deactivated];
        $offset = Person::query()->max('id') ?? 0;

        for ($done = 0; $done < $count; $done += self::CHUNK) {
            DB::transaction(function () use ($done, $count, $password, $statuses, $offset): void {
                $size = min(self::CHUNK, $count - $done);
                $now = now();
                $people = [];
                for ($i = 0; $i < $size; $i++) {
                    $people[] = [
                        'first_name' => fake()->firstName(),
                        'last_name' => fake()->lastName(),
                        // A separate domain keeps load-test accounts out of the quick sign-in list.
                        'email' => sprintf('person.%06d@load.bz-crm.test', $offset + $done + $i + 1),
                        'person_type' => 'employee',
                        'preferred_locale' => fake()->randomElement(['ro', 'ro', 'ru', 'en']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                Person::query()->insert($people);

                $created = Person::query()->where('id', '>', $offset)->whereDoesntHave('user')->orderBy('id')->limit($size)->get(['id', 'email', 'preferred_locale']);
                User::query()->insert($created->map(fn (Person $person, int $i): array => [
                    'person_id' => $person->id,
                    'email' => $person->email,
                    'password' => $password,
                    'status' => ($i % 10 === 0 ? $statuses[$i % 3] : UserStatus::Active)->value,
                    'locale' => $person->preferred_locale,
                    'email_verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
        }
    }
}
