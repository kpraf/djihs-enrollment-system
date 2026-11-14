<?php

namespace App\Services\Reports;

use App\Enums\EnrollmentStatus;
use App\Enums\GradeLevel;
use App\Enums\LearnerType;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the Analytics page: overview, demographics, distribution,
 * strands, trends over the years, and a two-year comparison.
 * Only enrolled learners are counted.
 */
class AnalyticsReport
{
    /**
     * @return array<string, mixed>
     */
    public function forYear(SchoolYear $year, ?int $grade = null): array
    {
        $rows = $this->rows($year->id, $grade);
        $tally = fn (Collection $r) => [
            'total' => (int) $r->sum('n'),
            'male' => (int) $r->where('sex', 'male')->sum('n'),
            'female' => (int) $r->where('sex', 'female')->sum('n'),
            'pwd' => (int) $r->sum('pwd'),
            'balik_aral' => (int) $r->where('learner_type', LearnerType::BalikAral->value)->sum('n'),
            'transferee' => (int) $r->where('learner_type', LearnerType::Transferee->value)->sum('n'),
            'indigenous' => (int) $r->sum('ip'),
            'four_ps' => (int) $r->sum('fourps'),
        ];

        $strands = Strand::orderBy('sort_order')->get();

        return [
            'overview' => $tally($rows),
            'by_grade' => collect(GradeLevel::cases())
                ->map(fn (GradeLevel $g) => ['grade' => $g->value, 'label' => $g->label(), ...$tally($rows->where('grade_level', $g->value))])
                ->filter(fn ($r) => $r['total'] > 0)->values(),
            'by_learner_type' => array_map(fn (LearnerType $t) => [
                'type' => $t->value, 'label' => $t->label(), 'total' => (int) $rows->where('learner_type', $t->value)->sum('n'),
            ], LearnerType::cases()),
            'by_strand' => $strands->map(fn (Strand $s) => [
                'code' => $s->code, 'name' => $s->name, 'track' => $s->track->value, ...$tally($rows->where('strand_id', $s->id)),
            ])->filter(fn ($r) => $r['total'] > 0)->values(),
        ];
    }

    /**
     * Enrolled learners per school year, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function trends(?int $grade = null): array
    {
        $counts = Enrollment::query()
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->where('enrollments.status', EnrollmentStatus::Enrolled)
            ->when($grade, fn ($q) => $q->where('enrollments.grade_level', $grade))
            ->groupBy('enrollments.school_year_id', 'students.sex')
            ->select('enrollments.school_year_id', 'students.sex', DB::raw('count(*) as n'))
            ->toBase()->get();

        return SchoolYear::orderBy('start_year')->get()->map(function (SchoolYear $y) use ($counts) {
            $mine = $counts->where('school_year_id', $y->id);

            return [
                'year' => $y->name,
                'total' => (int) $mine->sum('n'),
                'male' => (int) $mine->where('sex', 'male')->sum('n'),
                'female' => (int) $mine->where('sex', 'female')->sum('n'),
            ];
        })->values()->all();
    }

    /**
     * Two school years side by side, per grade, sex and learner type.
     *
     * @return array<string, mixed>
     */
    public function compare(SchoolYear $a, SchoolYear $b): array
    {
        $ra = $this->forYear($a);
        $rb = $this->forYear($b);
        $grades = collect([...$ra['by_grade'], ...$rb['by_grade']])->pluck('grade')->unique()->sort()->values();

        return [
            'a' => $a->name,
            'b' => $b->name,
            'overview' => ['a' => $ra['overview'], 'b' => $rb['overview']],
            'by_grade' => $grades->map(function (int $g) use ($ra, $rb) {
                $x = collect($ra['by_grade'])->firstWhere('grade', $g) ?? ['total' => 0, 'male' => 0, 'female' => 0, 'pwd' => 0];
                $y = collect($rb['by_grade'])->firstWhere('grade', $g) ?? ['total' => 0, 'male' => 0, 'female' => 0, 'pwd' => 0];

                return [
                    'label' => "Grade {$g}",
                    'a' => $x['total'], 'b' => $y['total'], 'change' => $y['total'] - $x['total'],
                    'male_a' => $x['male'], 'male_b' => $y['male'], 'female_a' => $x['female'], 'female_b' => $y['female'],
                    'pwd_a' => $x['pwd'], 'pwd_b' => $y['pwd'],
                ];
            })->values(),
            'by_learner_type' => collect($ra['by_learner_type'])->map(fn ($t, $i) => [
                'label' => $t['label'], 'a' => $t['total'], 'b' => $rb['by_learner_type'][$i]['total'],
            ])->values(),
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function rows(int $yearId, ?int $grade): Collection
    {
        return Enrollment::query()
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->where('enrollments.school_year_id', $yearId)
            ->where('enrollments.status', EnrollmentStatus::Enrolled)
            ->when($grade, fn ($q) => $q->where('enrollments.grade_level', $grade))
            ->groupBy('enrollments.grade_level', 'enrollments.strand_id', 'enrollments.learner_type', 'students.sex')
            ->select([
                'enrollments.grade_level', 'enrollments.strand_id', 'enrollments.learner_type', 'students.sex',
                DB::raw('count(*) as n'),
                DB::raw('sum(case when students.is_pwd then 1 else 0 end) as pwd'),
                DB::raw('sum(case when students.is_indigenous then 1 else 0 end) as ip'),
                DB::raw('sum(case when students.is_4ps_beneficiary then 1 else 0 end) as fourps'),
            ])
            ->toBase()->get()
            ->map(function ($r) {
                $r->grade_level = (int) $r->grade_level;
                $r->strand_id = $r->strand_id === null ? null : (int) $r->strand_id;

                return $r;
            });
    }
}
