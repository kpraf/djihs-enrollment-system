<?php

namespace App\Http\Controllers;

use App\Enums\GradeLevel;
use App\Enums\Role;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Strand;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage Sections: sections per school year, their adviser and capacity,
 * and the subject teachers assigned to each.
 */
class SectionController extends Controller
{
    public function index(Request $request): Response
    {
        $years = SchoolYear::orderByDesc('start_year')->get();
        $year = $years->firstWhere('id', $request->integer('school_year')) ?? $years->firstWhere('is_active', true) ?? $years->first();

        $sections = Section::with(['strand:id,code', 'adviser:id,first_name,last_name', 'teacherAssignments.teacher:id,first_name,last_name'])
            ->withCount('enrollments')
            ->where('school_year_id', $year?->id)
            ->orderBy('grade_level')->orderBy('name')
            ->get()
            ->map(fn (Section $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'grade_level' => $s->grade_level->value,
                'strand_id' => $s->strand_id,
                'strand' => $s->strand?->code,
                'adviser_id' => $s->adviser_id,
                'adviser' => $s->adviser?->name,
                'capacity' => $s->capacity,
                'is_active' => $s->is_active,
                'filled' => $s->enrollments_count,
                'subject_teachers' => $s->teacherAssignments->map(fn (TeacherAssignment $t) => [
                    'id' => $t->id,
                    'subject_name' => $t->subject_name,
                    'subject_code' => $t->subject_code,
                    'teacher' => $t->teacher?->name,
                    'user_id' => $t->user_id,
                ])->values(),
            ]);

        return Inertia::render('sections/index', [
            'sections' => $sections,
            'schoolYears' => $years->map->only(['id', 'name', 'is_active'])->values(),
            'selectedYear' => $year?->only(['id', 'name']),
            'options' => [
                'grades' => GradeLevel::options(),
                'strands' => Strand::where('is_active', true)->orderBy('sort_order')->get(['id', 'code']),
                'advisers' => $this->staff([Role::Adviser]),
                'subjectTeachers' => $this->staff([Role::SubjectTeacher, Role::Adviser]),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->save(new Section, $data);

        return back()->with('success', "Section {$data['name']} created.");
    }

    public function update(Request $request, Section $section): RedirectResponse
    {
        $data = $this->validated($request, $section);

        if ($data['capacity'] < $section->enrollments()->count()) {
            throw ValidationException::withMessages(['capacity' => 'Capacity cannot be lower than the '.$section->enrollments()->count().' learners already in this section.']);
        }

        if (($data['grade_level'] != $section->grade_level->value || $data['strand_id'] != $section->strand_id) && $section->enrollments()->exists()) {
            throw ValidationException::withMessages(['grade_level' => 'Move the learners out before changing this section\'s grade or strand.']);
        }

        $this->save($section, $data);

        return back()->with('success', 'Section updated.');
    }

    public function destroy(Section $section): RedirectResponse
    {
        if ($section->enrollments()->exists()) {
            return back()->with('error', "{$section->name} still has learners. Move them out first, or deactivate the section.");
        }

        $section->delete();

        return back()->with('success', 'Section deleted.');
    }

    public function addTeacher(Request $request, Section $section): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('is_active', true)],
            'subject_name' => ['required', 'string', 'max:100'],
            'subject_code' => ['nullable', 'string', 'max:30'],
        ]);

        try {
            // Own savepoint: on PostgreSQL a failed insert would otherwise abort
            // any surrounding transaction.
            DB::transaction(fn () => $section->teacherAssignments()->create($data));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['subject_name' => 'This section already has a teacher for that subject.']);
        }

        return back()->with('success', 'Subject teacher assigned.');
    }

    public function removeTeacher(TeacherAssignment $assignment): RedirectResponse
    {
        $assignment->delete();

        return back()->with('success', 'Subject teacher removed.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(Section $section, array $data): void
    {
        try {
            DB::transaction(fn () => $section->fill($data)->save());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'There is already a section with this name for this grade and school year.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Section $section = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'school_year_id' => ['required', 'exists:school_years,id'],
            'grade_level' => ['required', Rule::enum(GradeLevel::class)],
            'strand_id' => ['nullable', 'exists:strands,id'],
            'name' => ['required', 'string', 'max:60', Rule::unique('sections', 'name')->where('school_year_id', $request->input('school_year_id'))->where('grade_level', $request->input('grade_level'))->ignore($section)],
            'adviser_id' => ['nullable', Rule::exists('users', 'id')->where('role', Role::Adviser->value)],
            'capacity' => ['required', 'integer', 'between:1,80'],
            'is_active' => ['boolean'],
        ]);

        $grade = GradeLevel::from((int) $data['grade_level']);
        if ($grade->isSeniorHigh() && empty($data['strand_id'])) {
            throw ValidationException::withMessages(['strand_id' => 'Grade 11 and 12 sections need a strand.']);
        }
        if (! $grade->isSeniorHigh()) {
            $data['strand_id'] = null;
        }

        return $data;
    }

    /**
     * @param  list<Role>  $roles
     * @return Collection<int, array{id: int, name: string}>
     */
    private function staff(array $roles)
    {
        return User::where('is_active', true)->whereIn('role', $roles)->orderBy('last_name')->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]);
    }
}
