<?php

namespace App\Http\Controllers;

use App\Models\SchoolYear;
use App\Models\Student;
use App\Services\Enrollment\DuplicateFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Called by the enrollment form as soon as a full LRN is typed, so the
 * encoder learns right away whether the learner is already recorded.
 */
class StudentLookupController extends Controller
{
    public function __invoke(Request $request, DuplicateFinder $duplicates): JsonResponse
    {
        $data = $request->validate(['lrn' => ['required', 'digits:12']]);
        $student = Student::where('lrn', $data['lrn'])->first();

        if ($student === null) {
            return response()->json(['student' => null, 'enrollment' => null]);
        }

        $year = SchoolYear::active();
        $enrollment = $year ? $duplicates->enrollmentFor($student, $year) : null;

        return response()->json([
            'student' => [
                ...$student->only($student->getFillable()),
                'birthdate' => $student->birthdate?->toDateString(),
                'sex' => $student->sex->value,
                'full_name' => $student->full_name,
            ],
            'enrollment' => $enrollment ? [
                'id' => $enrollment->id,
                'summary' => DuplicateFinder::describe($enrollment),
                'status' => $enrollment->status->value,
            ] : null,
        ]);
    }
}
