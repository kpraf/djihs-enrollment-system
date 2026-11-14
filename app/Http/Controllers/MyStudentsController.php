<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\TeacherAssignment;
use App\Support\TableExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Subject teacher: the classes they teach and the learners in each.
 */
class MyStudentsController extends Controller
{
    public function index(Request $request): Response
    {
        $classes = $this->classes($request);
        $selected = $classes->firstWhere('id', $request->integer('class')) ?? $classes->first();

        return Inertia::render('my-students/index', [
            'schoolYear' => SchoolYear::active()?->only(['id', 'name']),
            'classes' => $classes->map(fn (TeacherAssignment $a) => [
                'id' => $a->id,
                'subject' => $a->subject_name,
                'subject_code' => $a->subject_code,
                'section' => $a->section->label(),
                'grade_level' => $a->section->grade_level->value,
                'learners_count' => $a->section->enrollments_count,
            ])->values(),
            'selected' => $selected ? [
                'id' => $selected->id,
                'title' => "{$selected->subject_name} · {$selected->section->label()}",
                'learners' => $this->learners($selected)->map(fn (Enrollment $e) => [
                    'lrn' => $e->student->lrn,
                    'name' => $e->student->full_name,
                    'sex' => $e->student->sex->label(),
                ])->values(),
            ] : null,
        ]);
    }

    public function export(Request $request, TeacherAssignment $assignment): StreamedResponse
    {
        abort_unless($assignment->user_id === $request->user()->id, 403);
        $assignment->load('section.strand');

        return TableExport::csv(
            preg_replace('/[^A-Za-z0-9_-]+/', '_', "{$assignment->subject_name}_{$assignment->section->label()}").'.csv',
            ['Student Name', 'LRN', 'Sex'],
            $this->learners($assignment)->map(fn (Enrollment $e) => [$e->student->full_name, $e->student->lrn, $e->student->sex->label()]),
        );
    }

    /**
     * @return Collection<int, TeacherAssignment>
     */
    private function classes(Request $request)
    {
        $year = SchoolYear::active();

        return TeacherAssignment::with(['section' => fn ($q) => $q->with('strand:id,code')->withCount(['enrollments' => fn ($q) => $q->where('status', EnrollmentStatus::Enrolled)])])
            ->where('user_id', $request->user()->id)
            ->whereHas('section', fn ($q) => $q->where('school_year_id', $year?->id))
            ->get()
            ->sortBy(fn (TeacherAssignment $a) => [$a->section->grade_level->value, $a->section->name, $a->subject_name])
            ->values();
    }

    /**
     * @return Collection<int, Enrollment>
     */
    private function learners(TeacherAssignment $assignment)
    {
        return Enrollment::with('student')
            ->where('section_id', $assignment->section_id)
            ->where('status', EnrollmentStatus::Enrolled)
            ->get()
            ->sortBy(fn (Enrollment $e) => [$e->student->last_name, $e->student->first_name])
            ->values();
    }
}
