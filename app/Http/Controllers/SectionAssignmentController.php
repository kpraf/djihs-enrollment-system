<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Enums\GradeLevel;
use App\Exceptions\StaleRecordException;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Strand;
use App\Services\Sections\SectionAssigner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assign to Sections: place enrolled learners of the active school year into sections.
 */
class SectionAssignmentController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'grade' => ['nullable', 'integer'],
            'strand' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $year = SchoolYear::active();
        $grade = (int) ($filters['grade'] ?? 11);
        $strand = $filters['strand'] ?? null;

        $unassigned = Enrollment::with(['student:id,lrn,last_name,first_name,middle_name,extension_name,sex', 'strand:id,code'])
            ->where('school_year_id', $year?->id)
            ->where('status', EnrollmentStatus::Enrolled)
            ->where('grade_level', $grade)
            ->whereNull('section_id')
            ->when($strand, fn (Builder $q, $s) => $q->where('strand_id', $s))
            ->search($filters['search'] ?? null)
            ->orderByRaw('encoded_on is null')->orderBy('encoded_on')->orderBy('id')
            ->limit(300)
            ->get()
            ->map(fn (Enrollment $e) => [
                'id' => $e->id,
                'name' => $e->student->full_name,
                'lrn' => $e->student->lrn,
                'sex' => $e->student->sex->label(),
                'strand_id' => $e->strand_id,
                'strand' => $e->strand?->code,
            ]);

        $sections = Section::with(['strand:id,code', 'adviser:id,first_name,last_name', 'enrollments.student:id,lrn,last_name,first_name,middle_name,extension_name,sex'])
            ->where('school_year_id', $year?->id)
            ->where('grade_level', $grade)
            ->when($strand, fn (Builder $q, $s) => $q->where('strand_id', $s))
            ->orderBy('id')
            ->get()
            ->map(fn (Section $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'strand_id' => $s->strand_id,
                'strand' => $s->strand?->code,
                'adviser' => $s->adviser?->name,
                'capacity' => $s->capacity,
                'is_active' => $s->is_active,
                'learners' => $s->enrollments->sortBy(fn ($e) => $e->student->full_name)->map(fn (Enrollment $e) => [
                    'id' => $e->id,
                    'name' => $e->student->full_name,
                    'sex' => $e->student->sex->label(),
                ])->values(),
            ]);

        return Inertia::render('section-assignments/index', [
            'schoolYear' => $year?->only(['id', 'name']),
            'unassigned' => $unassigned,
            'sections' => $sections,
            'filters' => [...$filters, 'grade' => $grade],
            'options' => [
                'grades' => GradeLevel::options(),
                'strands' => Strand::orderBy('sort_order')->get(['id', 'code']),
            ],
        ]);
    }

    public function assign(Request $request, SectionAssigner $assigner): RedirectResponse
    {
        $data = $request->validate([
            'enrollment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'enrollment_ids.*' => ['integer', 'exists:enrollments,id'],
            'section_id' => ['required', 'exists:sections,id'],
        ]);
        $section = Section::findOrFail($data['section_id']);
        $done = 0;
        $problems = [];

        foreach ($data['enrollment_ids'] as $id) {
            try {
                $assigner->assign(Enrollment::findOrFail($id), $section);
                $done++;
            } catch (StaleRecordException $e) {
                $problems[] = $e->getMessage();
            }
        }

        $message = "Placed {$done} learner".($done === 1 ? '' : 's')." in {$section->name}.";

        return $problems
            ? back()->with('error', trim(($done ? $message.' ' : '').'Not placed: '.implode(' ', array_unique($problems))))
            : back()->with('success', $message);
    }

    public function unassign(Enrollment $enrollment, SectionAssigner $assigner): RedirectResponse
    {
        $assigner->unassign($enrollment);

        return back()->with('success', 'Learner removed from the section.');
    }

    public function auto(Request $request, SectionAssigner $assigner): RedirectResponse
    {
        $data = $request->validate(['grade' => ['required', 'integer'], 'strand' => ['nullable', 'integer']]);
        $year = SchoolYear::active();
        abort_if($year === null, 409);

        $result = $assigner->autoAssign($year->id, (int) $data['grade'], isset($data['strand']) ? (int) $data['strand'] : null);
        $message = "Auto-assigned {$result['assigned']} learner".($result['assigned'] === 1 ? '' : 's').'.';

        return $result['unplaced']
            ? back()->with('error', "{$message} {$result['unplaced']} could not be placed because no matching section has room.")
            : back()->with('success', $message);
    }

    public function clear(Section $section, SectionAssigner $assigner): RedirectResponse
    {
        $count = $assigner->clear($section);

        return back()->with('success', "Removed {$count} learners from {$section->name}.");
    }
}
