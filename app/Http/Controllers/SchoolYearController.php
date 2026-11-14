<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Models\SchoolYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Academic Year Management: one active year at a time; old years archived.
 */
class SchoolYearController extends Controller
{
    public function index(): Response
    {
        $years = SchoolYear::withCount([
            'enrollments',
            'enrollments as enrolled_count' => fn ($q) => $q->where('status', EnrollmentStatus::Enrolled),
            'sections',
        ])->orderByDesc('start_year')->get();

        return Inertia::render('school-years/index', [
            'years' => $years->map(fn (SchoolYear $y) => [
                'id' => $y->id,
                'name' => $y->name,
                'start_year' => $y->start_year,
                'is_active' => $y->is_active,
                'is_enrollment_open' => $y->is_enrollment_open,
                'is_archived' => $y->is_archived,
                'grade_10_completers' => $y->grade_10_completers,
                'enrollments_count' => $y->enrollments_count,
                'enrolled_count' => $y->enrolled_count,
                'sections_count' => $y->sections_count,
            ]),
            'suggestedStart' => max(SchoolYear::currentStartYear(), (int) SchoolYear::max('start_year') + 1),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'start_year' => ['required', 'integer', 'between:2000,2100', Rule::unique('school_years', 'start_year')],
            'grade_10_completers' => ['nullable', 'integer', 'min:0', 'max:5000'],
        ], ['start_year.unique' => 'That school year already exists.']);

        $year = SchoolYear::create($data);

        return back()->with('success', "School year {$year->name} created.");
    }

    public function update(Request $request, SchoolYear $schoolYear): RedirectResponse
    {
        $data = $request->validate([
            'grade_10_completers' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'is_enrollment_open' => ['boolean'],
        ]);

        $schoolYear->update($data);

        return back()->with('success', "School year {$schoolYear->name} updated.");
    }

    public function activate(SchoolYear $schoolYear): RedirectResponse
    {
        if ($schoolYear->is_archived) {
            return back()->with('error', 'Unarchive this school year before making it active.');
        }

        $schoolYear->activate();

        return back()->with('success', "{$schoolYear->name} is now the active school year.");
    }

    public function archive(Request $request, SchoolYear $schoolYear): RedirectResponse
    {
        $archive = (bool) $request->validate(['archived' => ['required', 'boolean']])['archived'];

        if ($archive && $schoolYear->is_active) {
            return back()->with('error', 'The active school year cannot be archived. Make another year active first.');
        }

        $schoolYear->update(['is_archived' => $archive, 'is_enrollment_open' => $archive ? false : $schoolYear->is_enrollment_open]);

        return back()->with('success', $archive ? "{$schoolYear->name} archived." : "{$schoolYear->name} restored from the archive.");
    }
}
