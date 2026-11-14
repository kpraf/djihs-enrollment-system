<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Enums\GradeLevel;
use App\Enums\LearnerType;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
        $year = isset($filters['school_year'])
            ? $years->firstWhere('id', $filters['school_year'])
            : ($years->firstWhere('is_active', true) ?? $years->first());

        $enrollments = Enrollment::query()
            ->with(['student:id,lrn,last_name,first_name,middle_name,extension_name,sex,birthdate', 'strand:id,code'])
            ->where('school_year_id', $year?->id)
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $q->whereHas('student', function (Builder $s) use ($search) {
                    $term = '%'.mb_strtoupper(trim($search)).'%';
                    $s->where('lrn', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('first_name', 'like', $term);
                });
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
}
