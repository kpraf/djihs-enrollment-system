<?php

namespace Database\Factories;

use App\Enums\EnrollmentStatus;
use App\Enums\GradeLevel;
use App\Enums\LearnerType;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement([
            ...array_fill(0, 12, LearnerType::OldStudent),
            ...array_fill(0, 3, LearnerType::NewStudent),
            LearnerType::Transferee,
            LearnerType::BalikAral,
            LearnerType::Als,
        ]);

        return [
            'student_id' => Student::factory(),
            'school_year_id' => SchoolYear::factory(),
            'grade_level' => fake()->randomElement(GradeLevel::seniorHigh()),
            'strand_id' => Strand::factory(),
            'learner_type' => $type,
            'is_irregular' => $type->defaultIrregular(),
            'status' => fake()->boolean(95) ? EnrollmentStatus::Enrolled : EnrollmentStatus::Cancelled,
            'weight_kg' => fake()->numberBetween(38, 80),
            'height_m' => fake()->randomFloat(2, 1.45, 1.80),
            'encoded_on' => fake()->dateTimeBetween('-30 days')->format('Y-m-d'),
        ];
    }

    public function enrolled(): static
    {
        return $this->state(fn () => ['status' => EnrollmentStatus::Enrolled]);
    }
}
