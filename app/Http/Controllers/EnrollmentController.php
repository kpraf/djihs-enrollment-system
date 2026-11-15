<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\DocumentType;
use App\Enums\EnrollmentStatus;
use App\Enums\GradeLevel;
use App\Enums\LearnerType;
use App\Exceptions\StaleRecordException;
use App\Http\Requests\Enrollment\StoreEnrollmentRequest;
use App\Http\Requests\Enrollment\UpdateEnrollmentRequest;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Strand;
use App\Models\Student;
use App\Services\Audit\AuditTrail;
use App\Services\Enrollment\EnrollLearner;
use App\Services\Enrollment\EnrollmentWorkflow;
use App\Services\Enrollment\RevisionService;
use App\Services\Privacy\AccessLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EnrollmentController extends Controller
{
    /**
     * Learners.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'school_year' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'grade' => ['nullable', 'integer'],
            'strand' => ['nullable', 'integer'],
            'section' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'learner_type' => ['nullable', 'string'],
            'documents' => ['nullable', 'in:incomplete'],
        ]);

        $years = SchoolYear::orderByDesc('start_year')->get();
        $year = (isset($filters['school_year']) ? $years->firstWhere('id', $filters['school_year']) : null)
            ?? $years->firstWhere('is_active', true)
            ?? $years->first();

        $enrollments = Enrollment::query()
            ->with(['student:id,lrn,last_name,first_name,middle_name,extension_name,sex,birthdate', 'strand:id,code', 'section:id,name'])
            ->where('school_year_id', $year?->id)
            ->search($filters['search'] ?? null)
            ->when($filters['grade'] ?? null, fn (Builder $q, int $g) => $q->where('grade_level', $g))
            ->when($filters['strand'] ?? null, fn (Builder $q, int $s) => $q->where('strand_id', $s))
            ->when($filters['section'] ?? null, fn (Builder $q, string $s) => $s === 'none' ? $q->whereNull('section_id') : $q->where('section_id', (int) $s))
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($filters['learner_type'] ?? null, fn (Builder $q, string $t) => $q->where('learner_type', $t))
            ->when(($filters['documents'] ?? null) === 'incomplete', fn (Builder $q) => $q->documentsIncomplete())
            ->orderByRaw('encoded_on is null')
            ->orderByDesc('encoded_on')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Enrollment $e) => [
                'id' => $e->id,
                'lrn' => $e->student->lrn,
                'name' => $e->student->full_name,
                'sex' => $e->student->sex->label(),
                'grade_level' => $e->grade_level->value,
                'strand' => $e->strand?->code,
                'section' => $e->section?->name,
                'learner_type' => $e->learner_type->label(),
                'is_irregular' => $e->is_irregular,
                'status' => $e->status->value,
                'encoded_on' => $e->encoded_on?->toDateString(),
                'remarks' => $e->remarks,
            ]);

        return Inertia::render('enrollments/index', [
            'enrollments' => $enrollments,
            'filters' => [...$filters, 'school_year' => $year?->id],
            'options' => [
                'schoolYears' => $years->map->only(['id', 'name'])->values(),
                'grades' => GradeLevel::options(),
                'strands' => Strand::orderBy('sort_order')->get(['id', 'code']),
                'sections' => Section::where('school_year_id', $year?->id)->orderBy('grade_level')->orderBy('name')->get(['id', 'name', 'grade_level']),
                'statuses' => EnrollmentStatus::options(),
                'learnerTypes' => LearnerType::options(),
            ],
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        $year = SchoolYear::active();

        if ($year === null) {
            return to_route('dashboard')->with('error', 'Set an active school year before encoding learners.');
        }

        return Inertia::render('enrollments/create', [
            'schoolYear' => $year->only(['id', 'name', 'is_enrollment_open']),
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreEnrollmentRequest $request, EnrollLearner $enroll): RedirectResponse
    {
        $year = SchoolYear::active();
        abort_if($year === null, 409, 'There is no active school year.');

        if (! $year->is_enrollment_open) {
            return back()->with('error', "Enrollment for school year {$year->name} is closed. Ask the ICT Coordinator to open it.");
        }

        $enrollment = $enroll->handle(
            $request->studentData(),
            $request->enrollmentData(),
            $year,
            $request->user(),
            $request->boolean('confirm_not_duplicate'),
        );

        return to_route('enrollments.create')->with('success', "Submitted {$enrollment->student->full_name} for Grade {$enrollment->grade_level->value}. It is now waiting for review.");
    }

    public function show(Request $request, Enrollment $enrollment): Response
    {
        $enrollment->load(['student', 'strand', 'section', 'schoolYear', 'encoder:id,first_name,last_name', 'reviewer:id,first_name,last_name', 'statusChanger:id,first_name,last_name', 'documents.updater:id,first_name,last_name', 'privacyNoticeGiver:id,first_name,last_name']);
        $user = $request->user();
        AccessLog::viewed($request, $enrollment, 'Learner record');

        return Inertia::render('enrollments/show', [
            'enrollment' => $this->present($enrollment),
            'documents' => $this->documents($enrollment),
            'revisions' => RevisionService::present($enrollment->revisionRequests()->with('requester:id,first_name,last_name', 'reviewer:id,first_name,last_name')->latest('id')->get()),
            'history' => AuditTrail::forEnrollment($enrollment),
            'can' => [
                'edit' => $user->hasAbility(Ability::EditEnrollments),
                'requestRevision' => $user->hasAbility(Ability::RequestRevisions),
                'review' => $user->hasAbility(Ability::ReviewEnrollments),
                'documents' => $user->hasAbility(Ability::ManageDocuments),
                'approveRevisions' => $user->hasAbility(Ability::EditEnrollments),
            ],
            'leavingStatuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], EnrollmentStatus::leaving()),
        ]);
    }

    public function edit(Enrollment $enrollment): Response
    {
        $enrollment->load(['student', 'strand', 'section', 'schoolYear']);

        return Inertia::render('enrollments/edit', [
            'enrollment' => $this->present($enrollment),
            'options' => $this->formOptions(),
            'asRevision' => ! request()->user()->hasAbility(Ability::EditEnrollments),
        ]);
    }

    /**
     * Staff who may edit save directly. Advisers' edits become a revision
     * request that a registrar or ICT coordinator approves.
     */
    public function update(UpdateEnrollmentRequest $request, Enrollment $enrollment, RevisionService $revisions): RedirectResponse
    {
        if (! $request->user()->hasAbility(Ability::EditEnrollments)) {
            $data = $request->validate(['justification' => ['required', 'string', 'max:500']]);
            $revision = $revisions->request($enrollment, $request, $data['justification']);

            return $revision
                ? to_route('enrollments.show', $enrollment)->with('success', 'Your changes were sent for approval.')
                : back()->with('error', 'Nothing was changed, so no request was sent.');
        }

        try {
            DB::transaction(function () use ($request, $enrollment) {
                Student::updateFromVersion($enrollment->student_id, $request->integer('student_version'), function (Student $student) use ($request) {
                    $data = $request->studentData();
                    if ($student->lrn !== $data['lrn'] && ($owner = Student::where('lrn', $data['lrn'])->first())) {
                        throw ValidationException::withMessages(['lrn' => "This LRN already belongs to {$owner->full_name}."]);
                    }
                    $student->fill($data);
                });

                Enrollment::updateFromVersion($enrollment->id, $request->integer('enrollment_version'), function (Enrollment $e) use ($request) {
                    $data = $request->enrollmentData();
                    // A changed grade or strand no longer fits the old section.
                    if ($e->section_id && ($e->grade_level !== $data['grade_level'] || $e->strand_id !== $data['strand_id'])) {
                        $data['section_id'] = null;
                    }
                    $e->fill($data);
                });
            });
        } catch (StaleRecordException $e) {
            return back()->with('error', $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['lrn' => 'Someone else just saved a learner with this LRN. Check the LRN.']);
        }

        return to_route('enrollments.show', $enrollment)->with('success', 'Changes saved.');
    }

    /**
     * Cancelled, dropped or transferred out.
     */
    public function leave(Request $request, Enrollment $enrollment, EnrollmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn ($s) => $s->value, EnrollmentStatus::leaving()))],
            'reason' => ['required', 'string', 'max:255'],
            'enrollment_version' => ['required', 'integer'],
        ]);

        try {
            $workflow->leave($enrollment, $request->user(), EnrollmentStatus::from($data['status']), $data['reason'], (int) $data['enrollment_version']);
        } catch (StaleRecordException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('enrollments.show', $enrollment)->with('success', 'Status changed to '.EnrollmentStatus::from($data['status'])->label().'.');
    }

    public function restore(Request $request, Enrollment $enrollment, EnrollmentWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['enrollment_version' => ['required', 'integer']]);

        try {
            $restored = $workflow->restore($enrollment, $request->user(), (int) $data['enrollment_version']);
        } catch (StaleRecordException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('enrollments.show', $enrollment)->with('success', $restored->status === EnrollmentStatus::Pending ? 'Sent back for review.' : 'Enrollment restored.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'grades' => GradeLevel::options(),
            'strands' => Strand::where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'track']),
            'learnerTypes' => LearnerType::options(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documents(Enrollment $e): array
    {
        $saved = $e->documents->keyBy(fn ($d) => $d->document_type->value);

        return array_map(function (DocumentType $t) use ($saved) {
            $d = $saved->get($t->value);

            return [
                'type' => $t->value,
                'label' => $t->label(),
                'is_submitted' => (bool) $d?->is_submitted,
                'is_verified' => (bool) $d?->is_verified,
                'notes' => $d?->notes,
                'updated_by' => $d?->updater?->name,
                'updated_at' => $d?->updated_at?->toIso8601String(),
            ];
        }, DocumentType::cases());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Enrollment $e): array
    {
        $s = $e->student;
        $submitted = $e->relationLoaded('documents') ? $e->documents->where('is_submitted', true)->map(fn ($d) => $d->document_type->value)->values()->all() : [];

        return [
            'id' => $e->id,
            'school_year' => $e->schoolYear->name,
            'grade_level' => $e->grade_level->value,
            'strand_id' => $e->strand_id,
            'strand' => $e->strand?->only(['code', 'name']),
            'section' => $e->relationLoaded('section') ? $e->section?->name : null,
            'learner_type' => $e->learner_type->value,
            'learner_type_label' => $e->learner_type->label(),
            'is_irregular' => $e->is_irregular,
            'status' => $e->status->value,
            'status_label' => $e->status->label(),
            'weight_kg' => $e->weight_kg,
            'height_m' => $e->height_m,
            'remarks' => $e->remarks,
            'encoded_on' => $e->encoded_on?->toDateString(),
            'encoder' => $e->relationLoaded('encoder') ? $e->encoder?->name : null,
            'reviewer' => $e->relationLoaded('reviewer') ? $e->reviewer?->name : null,
            'reviewed_at' => $e->reviewed_at?->toIso8601String(),
            'status_changed_at' => $e->status_changed_at?->toIso8601String(),
            'status_changer' => $e->relationLoaded('statusChanger') ? $e->statusChanger?->name : null,
            'status_reason' => $e->status_reason,
            'privacy_notice_at' => $e->privacy_notice_at?->toIso8601String(),
            'privacy_notice_by' => $e->relationLoaded('privacyNoticeGiver') ? $e->privacyNoticeGiver?->name : null,
            'documents_complete' => DocumentType::isComplete($submitted, $e->learner_type),
            'enrollment_version' => $e->lock_version,
            'student_version' => $s->lock_version,
            'student' => [
                ...$s->only($s->getFillable()),
                'birthdate' => $s->birthdate?->toDateString(),
                'sex' => $s->sex->value,
                'full_name' => $s->full_name,
                'age' => $s->ageOn(now()),
            ],
        ];
    }
}
