<?php

namespace App\Services\Reports;

use App\Enums\EnrollmentStatus;
use App\Enums\GradeLevel;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use Illuminate\Support\Facades\DB;

/**
 * DepEd-style efficiency indicators for the admin Metrics page:
 * transition, promotion, retention and dropout rates, resource adequacy
 * and plain-language insights.
 */
class MetricsReport
{
    /** Learners counted as "in school" for a year. */
    private const ACTIVE = [EnrollmentStatus::Enrolled, EnrollmentStatus::Dropped, EnrollmentStatus::TransferredOut];

    /**
     * @return array<string, mixed>
     */
    public function forYear(SchoolYear $year): array
    {
        $previous = SchoolYear::where('start_year', $year->start_year - 1)->first();
        $enrolled = $this->countByGrade($year, [EnrollmentStatus::Enrolled]);
        $base = $this->countByGrade($year, self::ACTIVE);
        $dropped = $this->countByGrade($year, [EnrollmentStatus::Dropped]);
        $total = array_sum($enrolled);
        $prevTotal = $previous ? array_sum($this->countByGrade($previous, [EnrollmentStatus::Enrolled])) : null;

        $grades = [];
        foreach (GradeLevel::cases() as $g) {
            $n = $enrolled[$g->value] ?? 0;
            if ($n === 0 && ($base[$g->value] ?? 0) === 0) {
                continue;
            }
            $promotion = $previous && $g->value > 7 ? $this->promotionRate($previous, $year, $g->value - 1) : null;
            $dropout = $this->rate($dropped[$g->value] ?? 0, $base[$g->value] ?? 0);
            $grades[] = [
                'grade' => $g->value,
                'label' => $g->label(),
                'enrollment' => $n,
                'promotion_rate' => $promotion,
                'retention_rate' => $previous ? $this->retentionRate($previous, $year, $g->value) : null,
                'dropout_rate' => $dropout,
                'status' => $dropout > 5 ? 'concern' : ($dropout > 2 ? 'watch' : 'good'),
            ];
        }

        $metrics = [
            'school_year' => $year->name,
            'previous_year' => $previous?->name,
            'total_enrollment' => $total,
            'growth_rate' => $prevTotal ? round(($total - $prevTotal) / $prevTotal * 100, 2) : null,
            'transition_rate' => $this->transitionRate($year, $previous),
            'promotion_rate' => $previous ? $this->promotionRate($previous, $year) : null,
            'retention_rate' => $previous ? $this->retentionRate($previous, $year) : null,
            'dropout_rate' => $this->rate(array_sum($dropped), array_sum($base)),
            'grades' => $grades,
            'resources' => $this->resources($year),
            'trends' => $this->trends(),
        ];
        $metrics['insights'] = $this->insights($metrics);

        return $metrics;
    }

    /**
     * Grade 10 completers who continued to Grade 11 here. Uses last year's
     * Grade 10 learners, or the completer count entered for this year.
     */
    private function transitionRate(SchoolYear $year, ?SchoolYear $previous): ?float
    {
        $g11 = Enrollment::where('school_year_id', $year->id)->where('grade_level', 11)->where('status', EnrollmentStatus::Enrolled)->count();
        $g10 = $previous ? Enrollment::where('school_year_id', $previous->id)->where('grade_level', 10)->where('status', EnrollmentStatus::Enrolled)->count() : 0;
        $g10 = $g10 ?: (int) $year->grade_10_completers;

        return $g10 ? $this->rate($g11, $g10) : null;
    }

    /**
     * Of last year's learners (in one grade, or all but Grade 12), the share
     * enrolled this year in the next grade up.
     */
    private function promotionRate(SchoolYear $previous, SchoolYear $year, ?int $fromGrade = null): ?float
    {
        $cohort = Enrollment::where('school_year_id', $previous->id)->where('status', EnrollmentStatus::Enrolled)
            ->when($fromGrade, fn ($q) => $q->where('grade_level', $fromGrade), fn ($q) => $q->where('grade_level', '<', 12));
        $size = (clone $cohort)->count();
        if ($size === 0) {
            return null;
        }

        $promoted = (clone $cohort)->whereExists(fn ($q) => $q->select(DB::raw(1))->from('enrollments as now')
            ->whereColumn('now.student_id', 'enrollments.student_id')
            ->where('now.school_year_id', $year->id)
            ->where('now.status', EnrollmentStatus::Enrolled->value)
            ->whereRaw('now.grade_level = enrollments.grade_level + 1'))->count();

        return $this->rate($promoted, $size);
    }

    /**
     * Of last year's learners (excluding Grade 12, who complete), the share
     * enrolled again this year in any grade.
     */
    private function retentionRate(SchoolYear $previous, SchoolYear $year, ?int $grade = null): ?float
    {
        $cohort = Enrollment::where('school_year_id', $previous->id)->where('status', EnrollmentStatus::Enrolled)->where('grade_level', '<', 12)
            ->when($grade, fn ($q) => $q->where('grade_level', $grade - 1));
        $size = (clone $cohort)->count();
        if ($size === 0) {
            return null;
        }

        $back = (clone $cohort)->whereExists(fn ($q) => $q->select(DB::raw(1))->from('enrollments as now')
            ->whereColumn('now.student_id', 'enrollments.student_id')
            ->where('now.school_year_id', $year->id)
            ->where('now.status', EnrollmentStatus::Enrolled->value))->count();

        return $this->rate($back, $size);
    }

    /**
     * @return array<string, mixed>
     */
    public function resources(SchoolYear $year): array
    {
        $sections = Section::withCount(['enrollments' => fn ($q) => $q->where('status', EnrollmentStatus::Enrolled)])
            ->where('school_year_id', $year->id)->where('is_active', true)->get();
        $capacity = $sections->sum('capacity');
        $seated = $sections->sum('enrollments_count');
        $enrolled = Enrollment::where('school_year_id', $year->id)->where('status', EnrollmentStatus::Enrolled)->count();

        return [
            'sections' => $sections->count(),
            'capacity' => $capacity,
            'learners_per_section' => $sections->count() ? round($seated / $sections->count(), 1) : null,
            'fill_rate' => $capacity ? round($seated / $capacity * 100, 1) : null,
            'nearly_full' => $sections->filter(fn ($s) => $s->capacity && $s->enrollments_count / $s->capacity >= 0.9)->count(),
            'without_adviser' => $sections->whereNull('adviser_id')->count(),
            'unassigned_learners' => $enrolled - $seated,
            'advisers' => $sections->whereNotNull('adviser_id')->pluck('adviser_id')->unique()->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trends(): array
    {
        return SchoolYear::orderBy('start_year')->get()->map(function (SchoolYear $y) {
            $base = array_sum($this->countByGrade($y, self::ACTIVE));

            return [
                'year' => $y->name,
                'enrollment' => array_sum($this->countByGrade($y, [EnrollmentStatus::Enrolled])),
                'dropout_rate' => $this->rate(array_sum($this->countByGrade($y, [EnrollmentStatus::Dropped])), $base),
            ];
        })->values()->all();
    }

    /**
     * Plain-language observations, like the legacy "Key Insights" panel.
     *
     * @param  array<string, mixed>  $m
     * @return list<array{tone: string, text: string}>
     */
    private function insights(array $m): array
    {
        $out = [];
        if ($m['growth_rate'] !== null) {
            $out[] = $m['growth_rate'] >= 0
                ? ['tone' => 'good', 'text' => "Enrollment grew {$m['growth_rate']}% compared with {$m['previous_year']}."]
                : ['tone' => 'watch', 'text' => 'Enrollment fell '.abs($m['growth_rate'])."% compared with {$m['previous_year']}."];
        }
        if ($m['transition_rate'] !== null && $m['transition_rate'] < 75) {
            $out[] = ['tone' => 'watch', 'text' => "Only {$m['transition_rate']}% of Grade 10 completers continued to Grade 11 here. Consider reaching out to those who did not enroll."];
        }
        if ($m['dropout_rate'] > 2) {
            $out[] = ['tone' => 'concern', 'text' => "The dropout rate is {$m['dropout_rate']}%. Review learners marked dropped and follow up with their families."];
        }
        $r = $m['resources'];
        if ($r['unassigned_learners'] > 0) {
            $out[] = ['tone' => 'watch', 'text' => "{$r['unassigned_learners']} enrolled learners are not yet in a section."];
        }
        if ($r['without_adviser'] > 0) {
            $out[] = ['tone' => 'watch', 'text' => "{$r['without_adviser']} sections have no adviser assigned."];
        }
        if ($r['nearly_full'] > 0) {
            $out[] = ['tone' => 'watch', 'text' => "{$r['nearly_full']} sections are at least 90% full. Consider opening another section."];
        }
        if ($out === []) {
            $out[] = ['tone' => 'good', 'text' => 'No issues found for this school year.'];
        }

        return $out;
    }

    /**
     * @param  list<EnrollmentStatus>  $statuses
     * @return array<int, int> grade => count
     */
    private function countByGrade(SchoolYear $year, array $statuses): array
    {
        return Enrollment::where('school_year_id', $year->id)->whereIn('status', $statuses)
            ->groupBy('grade_level')->select('grade_level', DB::raw('count(*) as n'))
            ->toBase()->pluck('n', 'grade_level')->map(fn ($n) => (int) $n)->all();
    }

    private function rate(int $part, int $whole): float
    {
        return $whole ? round($part / $whole * 100, 2) : 0.0;
    }
}
