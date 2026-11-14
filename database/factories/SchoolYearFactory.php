<?php

namespace Database\Factories;

use App\Models\SchoolYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolYear>
 */
class SchoolYearFactory extends Factory
{
    public function definition(): array
    {
        return [
            'start_year' => fake()->unique()->numberBetween(2020, 2035),
            'is_active' => false,
            'is_enrollment_open' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true, 'is_enrollment_open' => true]);
    }
}
