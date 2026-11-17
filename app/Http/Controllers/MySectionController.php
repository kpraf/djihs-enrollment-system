<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use App\Services\Exports\Sf1Exporter;
use App\Services\Exports\Sf8Exporter;
use App\Services\Privacy\AccessLog;
use App\Support\ExportName;
use App\Support\TableExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Adviser: the sections they advise, with the learner list and exports.
 */
class MySectionController extends Controller
{
    public function index(Request $request): Response
    {
        $year = SchoolYear::active();
        $sections = Section::with('strand:id,code')->withCount(['enrollments' => fn ($q) => $q->where('status', EnrollmentStatus::Enrolled)])
            ->where('adviser_id', $request->user()->id)
            ->where('school_year_id', $year?->id)
            ->orderBy('grade_level')->orderBy('name')
            ->get();

        $selected = $sections->firstWhere('id', $request->integer('section')) ?? $sections->first();

        return Inertia::render('my-section/index', [
            'schoolYear' => $year?->only(['id', 'name']),
            'sections' => $sections->map(fn (Section $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'label' => $s->label(),
                'capacity' => $s->capacity,
                'filled' => $s->enrollments_count,
            ])->values(),
            'selected' => $selected ? [
                'id' => $selected->id,
                'label' => $selected->label(),
                'learners' => $this->learners($selected)->map(fn (Enrollment $e) => [
                    'id' => $e->id,
                    'lrn' => $e->student->lrn,
                    'name' => $e->student->full_name,
                    'sex' => $e->student->sex->label(),
                    'contact_number' => $e->student->contact_number,
                    'status' => $e->status->value,
                ])->values(),
            ] : null,
        ]);
    }

    public function export(Request $request, Section $section, string $format): HttpResponse
    {
        $this->authorizeSection($request->user(), $section);
        $rows = $this->learners($section)->values()->map(fn (Enrollment $e, int $i) => [
            $i + 1, $e->student->lrn, $e->student->full_name, $e->student->sex->label(), $e->student->contact_number, $e->status->label(),
        ]);
        $headers = ['#', 'LRN', 'Student Name', 'Sex', 'Contact Number', 'Status'];
        $section->loadMissing('schoolYear');
        $name = ExportName::make('Class_List', $section->label(), ExportName::schoolYear($section->schoolYear?->name));
        AccessLog::exported($request, $section, 'Class list ('.($format === 'xlsx' ? 'Excel' : 'CSV').')');

        return $format === 'xlsx'
            ? TableExport::xlsx("{$name}.xlsx", [$section->name => [$headers, $rows]])
            : TableExport::csv("{$name}.csv", $headers, $rows);
    }

    public function sf1(Request $request, Section $section, Sf1Exporter $exporter): HttpResponse
    {
        $this->authorizeSection($request->user(), $section);
        AccessLog::exported($request, $section, 'School Form 1 (SF1)');

        return $exporter->download($section);
    }

    public function sf8(Request $request, Section $section, Sf8Exporter $exporter): HttpResponse
    {
        $this->authorizeSection($request->user(), $section);
        AccessLog::exported($request, $section, 'School Form 8 (SF8)');

        return $exporter->download($section);
    }

    public function print(Request $request, Section $section): Response
    {
        $this->authorizeSection($request->user(), $section);
        $section->load(['schoolYear', 'adviser']);
        AccessLog::exported($request, $section, 'Printable class list');

        return Inertia::render('print/class-list', [
            'title' => $section->label(),
            'documentTitle' => 'DJIHS Class List - '.str_replace(' - ', ' ', $section->label()).' - SY '.$section->schoolYear->name,
            'subtitle' => 'School year '.$section->schoolYear->name.($section->adviser ? ' · Adviser: '.$section->adviser->name : ''),
            'learners' => $this->learners($section)->values()->map(fn (Enrollment $e) => [
                'lrn' => $e->student->lrn, 'name' => $e->student->full_name, 'sex' => $e->student->sex->label(), 'contact_number' => $e->student->contact_number,
            ]),
        ]);
    }

    /**
     * @return Collection<int, Enrollment>
     */
    private function learners(Section $section)
    {
        return Enrollment::with('student')
            ->where('section_id', $section->id)
            ->where('status', EnrollmentStatus::Enrolled)
            ->get()
            ->sortBy(fn (Enrollment $e) => [$e->student->sex->value === 'male' ? 0 : 1, $e->student->last_name, $e->student->first_name]);
    }

    /**
     * The section's adviser, or staff who manage sections or records.
     */
    private function authorizeSection(User $user, Section $section): void
    {
        abort_unless(
            $section->adviser_id === $user->id || $user->hasAbility(Ability::ManageSections) || $user->hasAbility(Ability::EditEnrollments),
            403,
        );
    }
}
