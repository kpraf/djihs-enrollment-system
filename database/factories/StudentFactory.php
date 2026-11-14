<?php

namespace Database\Factories;

use App\Enums\Sex;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        $sex = fake()->randomElement(Sex::cases());

        return [
            'lrn' => fake()->unique()->numerify('1######'.'#####'),
            'last_name' => mb_strtoupper(fake()->lastName()),
            'first_name' => mb_strtoupper(fake()->firstName($sex->value)),
            'middle_name' => mb_strtoupper(fake()->lastName()),
            'birthdate' => fake()->dateTimeBetween('-19 years', '-15 years')->format('Y-m-d'),
            'sex' => $sex,
            'is_indigenous' => fake()->boolean(2),
            'is_4ps_beneficiary' => fake()->boolean(8),
            'house_number' => (string) fake()->buildingNumber(),
            'street' => mb_strtoupper(fake()->streetName()),
            'barangay' => fake()->randomElement(['DON JOSE', 'POOC', 'LOOC', 'TAGAPO', 'SINALHAN']),
            'municipality' => fake()->randomElement(['SANTA ROSA', 'SILANG', 'BIÑAN']),
            'province' => fake()->randomElement(['LAGUNA', 'CAVITE']),
            'zip_code' => fake()->randomElement(['4026', '4118', '4024']),
            'country' => 'Philippines',
            'guardian_last_name' => mb_strtoupper(fake()->lastName()),
            'guardian_first_name' => mb_strtoupper(fake()->firstName()),
            'guardian_contact_number' => fake()->numerify('09#########'),
        ];
    }
}
