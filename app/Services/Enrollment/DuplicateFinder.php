<?php

namespace App\Services\Enrollment;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Answers "is this learner already in the system?" for the encoding form,
 * the LRN lookup and the spreadsheet importer, so all three agree.
 */
class DuplicateFinder
{
    /**
     * Learners with the same name and birth date but a different LRN.
     * Usually a mistyped LRN, occasionally a real namesake.
     *
     * @return Collection<int, Student>
     */
    public function sameNameAndBirthdate(string $lastName, string $firstName, ?string $birthdate, ?string $exceptLrn = null, ?int $exceptId = null): Collection
    {
        if ($birthdate === null) {
            return collect();
        }

        return Student::query()
            ->whereRaw('upper(last_name) = ?', [mb_strtoupper(trim($lastName))])
            ->whereRaw('upper(first_name) = ?', [mb_strtoupper(trim($firstName))])
            ->whereDate('birthdate', $birthdate)
            ->when($exceptLrn, fn ($q) => $q->where(fn ($q) => $q->whereNull('lrn')->orWhere('lrn', '!=', $exceptLrn)))
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->limit(5)
            ->get();
    }

    /**
     * Same birth date, or same first and last name. Lets a later correction
     * of one of them still match the same learner.
     */
    public function isSamePerson(Student $student, string $lastName, string $firstName, ?string $birthdate): bool
    {
        $sameBirthdate = $birthdate !== null && $student->birthdate?->toDateString() === $birthdate;
        $sameName = mb_strtoupper($student->last_name) === mb_strtoupper(trim($lastName))
            && mb_strtoupper($student->first_name) === mb_strtoupper(trim($firstName));

        return $sameBirthdate || $sameName;
    }

    public function enrollmentFor(Student $student, SchoolYear $year): ?Enrollment
    {
        return Enrollment::with(['strand:id,code', 'encoder:id,first_name,last_name'])
            ->where('student_id', $student->id)
            ->where('school_year_id', $year->id)
            ->first();
    }

    /**
     * One-line description used in duplicate messages.
     */
    public static function describe(Enrollment $e): string
    {
        $where = trim('Grade '.$e->grade_level->value.' '.($e->strand?->code ?? ''));
        $who = $e->encoder ? ' by '.$e->encoder->name : '';
        $when = $e->encoded_on ? ' on '.$e->encoded_on->format('M j, Y') : '';

        return "{$where}{$who}{$when}";
    }
}
