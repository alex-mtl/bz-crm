<?php

namespace Database\Factories;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'person_type' => 'applicant',
            'preferred_locale' => 'ro',
        ];
    }

    public function employee(): static
    {
        return $this->state(fn (array $attributes) => ['person_type' => 'employee']);
    }

    public function candidate(): static
    {
        return $this->state(fn (array $attributes) => ['person_type' => 'candidate']);
    }
}
