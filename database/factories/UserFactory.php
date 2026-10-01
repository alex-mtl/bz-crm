<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_id' => Person::factory()->employee(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'locale' => 'ro',
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::PendingApproval,
            'person_id' => Person::factory()->state(['person_type' => 'applicant']),
        ]);
    }

    public function rejected(): static
    {
        return $this->pending()->state(fn (array $attributes) => ['status' => UserStatus::Rejected]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Deactivated, 'deactivated_at' => now()]);
    }
}
