<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\EnrollmentStatus;
use App\Enums\GradeLevel;
use App\Enums\LearnerType;
use App\Exceptions\StaleRecordException;
use App\Http\Requests\Enrollment\StoreEnrollmentRequest;
use App\Http\Requests\Enrollment\UpdateEnrollmentRequest;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Models\Student;
use App\Services\Audit\AuditTrail;
use App\Services\Enrollment\DuplicateFinder;
use App\Services\Enrollment\EnrollLearner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EnrollmentController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'school_year' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'grade' => ['nullable', 'integer'],
            'strand' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'learner_type' => ['nullable', 'string'],
        ]);

        $years = SchoolYear::orderByDesc('start_year')->get();
        $year = (isset($filters['school_year']) ? $years->firstWhere('id', $filters['school_year']) : null)
            ?? $years->firstWhere('is_active', true)
            ?? $years->first();

        $enrollments = Enrollment::query()
            ->with(['student:id,lrn,last_name,first_name,middle_name,extension_name,sex,birthdate', 'strand:id,code'])
            ->where('school_year_id', $year?->id)
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                // Every word must match the LRN or one of the names, so
                // "juan dela cruz" and "dela cruz juan" both work.
                foreach (preg_split('/[\s,]+/', mb_strtoupper(trim($search)), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                    $q->whereHas('student', function (Builder $s) use ($word) {
                        $term = '%'.addcslashes($word, '%_\\').'%';
                        $s->where(fn (Builder $w) => $w->where('lrn', 'like', $term)
                            ->orWhereRaw('upper(last_name) like ?', [$term])
                            ->orWhereRaw('upper(first_name) like ?', [$term])
                            ->orWhereRaw('upper(middle_name) like ?', [$term]));
                    });
                }
            })
            ->when($filters['grade'] ?? null, fn (Builder $q, int $g) => $q->where('grade_level', $g))
            ->when($filters['strand'] ?? null, fn (Builder $q, int $s) => $q->where('strand_id', $s))
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($filters['learner_type'] ?? null, fn (Builder $q, string $t) => $q->where('learner_type', $t))
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
                'statuses' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], EnrollmentStatus::cases()),
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
            'schoolYear' => $year->only(['id', 'name']),
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreEnrollmentRequest $request, EnrollLearner $enroll): RedirectResponse
    {
        $year = SchoolYear::active();
        abort_if($year === null, 409, 'There is no active school year.');

        $enrollment = $enroll->handle(
            $request->studentData(),
            $request->enrollmentData(),
            $year,
            $request->user(),
            $request->boolean('confirm_not_duplicate'),
        );

        return to_route('enrollments.create')->with('success', "Enrolled {$enrollment->student->full_name} in Grade {$enrollment->grade_level->value}.");
    }

    public function show(Enrollment $enrollment): Response
    {
        $enrollment->load(['student', 'strand', 'schoolYear', 'encoder:id,first_name,last_name', 'canceller:id,first_name,last_name']);

        return Inertia::render('enrollments/show', [
            'enrollment' => $this->present($enrollment),
            'history' => AuditTrail::forEnrollment($enrollment),
            'canEdit' => request()->user()->can(Ability::EncodeEnrollments->value),
        ]);
    }

    public function edit(Enrollment $enrollment): Response
    {
        $enrollment->load(['student', 'strand', 'schoolYear']);

        return Inertia::render('enrollments/edit', [
            'enrollment' => $this->present($enrollment),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateEnrollmentRequest $request, Enrollment $enrollment, DuplicateFinder $duplicates): RedirectResponse
    {
        $studentData = $request->studentData();

        try {
            DB::transaction(function () use ($request, $enrollment, $studentData) {
                Student::updateFromVersion($enrollment->student_id, $request->integer('student_version'), function (Student $student) use ($studentData) {
                    if ($student->lrn !== $studentData['lrn'] && ($owner = Student::where('lrn', $studentData['lrn'])->first())) {
                        throw ValidationException::withMessages(['lrn' => "This LRN already belongs to {$owner->full_name}."]);
                    }
                    $student->fill($studentData);
                });

                Enrollment::updateFromVersion($enrollment->id, $request->integer('enrollment_version'), function (Enrollment $e) use ($request) {
                    $e->fill($request->enrollmentData());
                });
            });
        } catch (StaleRecordException $e) {
            return back()->with('error', $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['lrn' => 'Someone else just saved a learner with this LRN. Check the LRN.']);
        }

        return to_route('enrollments.show', $enrollment)->with('success', 'Changes saved.');
    }

    public function cancel(Request $request, Enrollment $enrollment): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'enrollment_version' => ['required', 'integer'],
        ]);

        try {
            Enrollment::updateFromVersion($enrollment->id, (int) $data['enrollment_version'], function (Enrollment $e) use ($data, $request) {
                if ($e->status === EnrollmentStatus::Cancelled) {
                    throw new StaleRecordException('This enrollment was already cancelled by someone else.');
                }
                $e->fill([
                    'status' => EnrollmentStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => $request->user()->id,
                    'cancellation_reason' => $data['reason'],
                ]);
            });
        } catch (StaleRecordException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('enrollments.show', $enrollment)->with('success', 'Enrollment cancelled.');
    }

    public function restore(Request $request, Enrollment $enrollment): RedirectResponse
    {
        $data = $request->validate(['enrollment_version' => ['required', 'integer']]);

        try {
            Enrollment::updateFromVersion($enrollment->id, (int) $data['enrollment_version'], function (Enrollment $e) {
                $e->fill(['status' => EnrollmentStatus::Enrolled, 'cancelled_at' => null, 'cancelled_by' => null, 'cancellation_reason' => null]);
            });
        } catch (StaleRecordException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('enrollments.show', $enrollment)->with('success', 'Enrollment restored.');
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
     * @return array<string, mixed>
     */
    private function present(Enrollment $e): array
    {
        $s = $e->student;

        return [
            'id' => $e->id,
            'school_year' => $e->schoolYear->name,
            'grade_level' => $e->grade_level->value,
            'strand_id' => $e->strand_id,
            'strand' => $e->strand?->only(['code', 'name']),
            'learner_type' => $e->learner_type->value,
            'learner_type_label' => $e->learner_type->label(),
            'is_irregular' => $e->is_irregular,
            'status' => $e->status->value,
            'weight_kg' => $e->weight_kg,
            'height_m' => $e->height_m,
            'remarks' => $e->remarks,
            'encoded_on' => $e->encoded_on?->toDateString(),
            'encoder' => $e->relationLoaded('encoder') ? $e->encoder?->name : null,
            'cancelled_at' => $e->cancelled_at?->toIso8601String(),
            'canceller' => $e->relationLoaded('canceller') ? $e->canceller?->name : null,
            'cancellation_reason' => $e->cancellation_reason,
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
