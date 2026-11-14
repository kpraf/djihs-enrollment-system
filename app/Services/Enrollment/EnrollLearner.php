<?php

namespace App\Services\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enrolls one learner from the encoding form.
 *
 * Safe when several staff encode at the same time:
 * - An existing learner's row is locked while it is checked, so two staff
 *   re-enrolling the same old student are processed one after the other and
 *   the second one is told it is already done.
 * - For a brand-new LRN there is no row to lock, so the database's unique
 *   constraints decide. The loser of the race gets a clear message, not a crash.
 */
class EnrollLearner
{
    public function __construct(private DuplicateFinder $duplicates) {}

    /**
     * @param  array<string, mixed>  $studentData
     * @param  array<string, mixed>  $enrollmentData
     *
     * @throws ValidationException
     */
    public function handle(array $studentData, array $enrollmentData, SchoolYear $year, User $actor, bool $confirmedNotDuplicate = false): Enrollment
    {
        if (! $confirmedNotDuplicate) {
            $this->guardAgainstLookalikes($studentData);
        }

        try {
            return DB::transaction(function () use ($studentData, $enrollmentData, $year, $actor) {
                $student = Student::where('lrn', $studentData['lrn'])->lockForUpdate()->first();

                if ($student) {
                    $this->guardExistingStudent($student, $studentData, $year);
                    $student->update($studentData);
                } else {
                    $student = Student::create($studentData);
                }

                return Enrollment::create([
                    ...$enrollmentData,
                    'student_id' => $student->id,
                    'school_year_id' => $year->id,
                    'status' => EnrollmentStatus::Enrolled,
                    'encoded_on' => now()->toDateString(),
                    'encoded_by' => $actor->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->lostRace($studentData['lrn'], $year);
        }
    }

    private function guardExistingStudent(Student $student, array $data, SchoolYear $year): void
    {
        if ($existing = $this->duplicates->enrollmentFor($student, $year)) {
            throw ValidationException::withMessages([
                'lrn' => "This learner is already enrolled for school year {$year->name}: ".DuplicateFinder::describe($existing).'.',
            ]);
        }

        if (! $this->duplicates->isSamePerson($student, $data['last_name'], $data['first_name'], $data['birthdate'] ?? null)) {
            throw ValidationException::withMessages([
                'lrn' => "This LRN already belongs to {$student->full_name}, born {$student->birthdate?->format('M j, Y')}. Check the LRN.",
            ]);
        }
    }

    private function guardAgainstLookalikes(array $data): void
    {
        $matches = $this->duplicates->sameNameAndBirthdate($data['last_name'], $data['first_name'], $data['birthdate'] ?? null, exceptLrn: $data['lrn']);

        if ($matches->isNotEmpty()) {
            $list = $matches->map(fn (Student $s) => $s->full_name.' (LRN '.($s->lrn ?? 'none').')')->join('; ');

            throw ValidationException::withMessages([
                'possible_duplicate' => "A learner with the same name and birth date is already recorded: {$list}. If this is a different person, tick the box to confirm.",
            ]);
        }
    }

    /**
     * Another request inserted the same learner or enrollment between our
     * checks and our insert.
     */
    private function lostRace(string $lrn, SchoolYear $year): ValidationException
    {
        $student = Student::where('lrn', $lrn)->first();
        $existing = $student ? $this->duplicates->enrollmentFor($student, $year) : null;

        return ValidationException::withMessages([
            'lrn' => $existing
                ? 'Someone else just enrolled this learner: '.DuplicateFinder::describe($existing).'. Nothing was saved.'
                : 'Someone else just saved a learner with this LRN. Nothing was saved. Search for the LRN and try again.',
        ]);
    }
}
