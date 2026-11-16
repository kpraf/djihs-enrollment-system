<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\EnrollmentStatus;
use App\Enums\GradeLevel;
use App\Exceptions\StaleRecordException;
use App\Models\Enrollment;
use App\Models\RevisionRequest;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Services\Enrollment\EnrollmentWorkflow;
use App\Services\Enrollment\RevisionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review Enrollments: approve or reject what was encoded on the form, and,
 * for registrars and ICT coordinators, advisers' revision requests.
 */
class ReviewController extends Controller
{
    /** Names listed in the Approve All confirmation; past this, it says how many more. */
    private const APPROVE_ALL_LIST_LIMIT = 500;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'grade' => ['nullable', 'integer'],
            'strand' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'tab' => ['nullable', 'in:enrollments,revisions'],
        ]);
        $year = SchoolYear::active();
        $canApproveRevisions = $request->user()->hasAbility(Ability::EditEnrollments);

        $pending = $this->pendingQuery($year, $filters)
            ->with(['student:id,lrn,last_name,first_name,middle_name,extension_name,sex', 'strand:id,code', 'encoder:id,first_name,last_name'])
            ->orderBy('encoded_on')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Enrollment $e) => [
                'id' => $e->id,
                'lrn' => $e->student->lrn,
                'name' => $e->student->full_name,
                'sex' => $e->student->sex->label(),
                'grade_level' => $e->grade_level->value,
                'strand' => $e->strand?->code,
                'learner_type' => $e->learner_type->label(),
                'is_irregular' => $e->is_irregular,
                'encoded_on' => $e->encoded_on?->toDateString(),
                'encoder' => $e->encoder?->name,
            ]);

        $revisions = $canApproveRevisions
            ? RevisionService::present(RevisionRequest::with(['enrollment.student', 'requester:id,first_name,last_name'])
                ->where('status', RevisionRequest::PENDING)->oldest()->limit(100)->get())
            : [];

        return Inertia::render('reviews/index', [
            'pending' => $pending,
            // Everyone "Approve All" would approve, across every page. Loaded
            // only when the confirmation opens, so the list can be checked first.
            'approveAllList' => Inertia::optional(fn () => $this->pendingQuery($year, $filters)
                ->with(['student:id,lrn,last_name,first_name,middle_name,extension_name', 'strand:id,code'])
                ->orderBy('encoded_on')
                ->orderBy('id')
                ->limit(self::APPROVE_ALL_LIST_LIMIT)
                ->get()
                ->map(fn (Enrollment $e) => [
                    'id' => $e->id,
                    'lrn' => $e->student->lrn,
                    'name' => $e->student->full_name,
                    'grade_level' => $e->grade_level->value,
                    'strand' => $e->strand?->code,
                ])),
            'revisions' => $revisions,
            'filters' => $filters,
            'stats' => [
                'pending' => $year ? Enrollment::where('school_year_id', $year->id)->where('status', EnrollmentStatus::Pending)->count() : 0,
                'approved_today' => Enrollment::where('status', EnrollmentStatus::Enrolled)->where('reviewed_at', '>=', now()->startOfDay())->count(),
                'enrolled' => $year ? Enrollment::where('school_year_id', $year->id)->where('status', EnrollmentStatus::Enrolled)->count() : 0,
                'revisions' => $canApproveRevisions ? RevisionRequest::where('status', RevisionRequest::PENDING)->count() : 0,
            ],
            'options' => [
                'grades' => GradeLevel::options(),
                'strands' => Strand::orderBy('sort_order')->get(['id', 'code']),
            ],
            'canApproveRevisions' => $canApproveRevisions,
        ]);
    }

    public function approve(Request $request, Enrollment $enrollment, EnrollmentWorkflow $workflow): RedirectResponse
    {
        try {
            $workflow->approve($enrollment, $request->user());
        } catch (StaleRecordException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Approved '.$enrollment->student->full_name.'.');
    }

    public function reject(Request $request, Enrollment $enrollment, EnrollmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $workflow->reject($enrollment, $request->user(), $data['reason']);
        } catch (StaleRecordException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Rejected '.$enrollment->student->full_name.'.');
    }

    /**
     * Approves every pending enrollment matching the current filters. Each one
     * goes through the same locked check, so any already reviewed by someone
     * else in the meantime are simply skipped.
     */
    public function approveAll(Request $request, EnrollmentWorkflow $workflow): RedirectResponse
    {
        $filters = $request->validate(['grade' => ['nullable', 'integer'], 'strand' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100']]);
        $approved = 0;
        $skipped = 0;

        foreach ($this->pendingQuery(SchoolYear::active(), $filters)->pluck('id') as $id) {
            try {
                $workflow->approve(Enrollment::findOrFail($id), $request->user());
                $approved++;
            } catch (StaleRecordException) {
                $skipped++;
            }
        }

        $message = "Approved {$approved} enrollment".($approved === 1 ? '' : 's').'.';

        return back()->with('success', $skipped ? "{$message} {$skipped} were already reviewed by someone else." : $message);
    }

    public function approveRevision(Request $request, RevisionRequest $revision, RevisionService $service): RedirectResponse
    {
        try {
            $service->approve($revision, $request->user(), $request->input('notes'));
        } catch (StaleRecordException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Changes approved and applied.');
    }

    public function rejectRevision(Request $request, RevisionRequest $revision, RevisionService $service): RedirectResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'max:255']]);

        try {
            $service->reject($revision, $request->user(), $data['notes']);
        } catch (StaleRecordException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Request rejected.');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Enrollment>
     */
    private function pendingQuery(?SchoolYear $year, array $filters): Builder
    {
        return Enrollment::query()
            ->where('school_year_id', $year?->id)
            ->where('status', EnrollmentStatus::Pending)
            ->search($filters['search'] ?? null)
            ->when($filters['grade'] ?? null, fn (Builder $q, int $g) => $q->where('grade_level', $g))
            ->when($filters['strand'] ?? null, fn (Builder $q, int $s) => $q->where('strand_id', $s));
    }
}
