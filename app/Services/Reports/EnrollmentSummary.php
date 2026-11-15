<?php

namespace App\Services\Reports;

use App\Enums\EnrollmentStatus;
use App\Enums\GradeLevel;
use App\Enums\LearnerType;
use App\Enums\Track;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the MONITORING and DAILY_MONITORING tabs of the school's
 * encoding workbook from the database, with no COUNTIFS formulas to maintain.
 */
class EnrollmentSummary
{
    /**
     * Counts of enrolled learners per grade and strand, split by sex,
     * learner type, indigenous status and 4Ps, like the MONITORING tab.
     *
     * @return array<string, mixed>
     */
    public function monitoring(SchoolYear $year): array
    {
        $rows = Enrollment::query()
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->where('enrollments.school_year_id', $year->id)
            ->where('enrollments.status', EnrollmentStatus::Enrolled)
            ->groupBy('enrollments.grade_level', 'enrollments.strand_id', 'students.sex', 'enrollments.learner_type')
            ->select([
                'enrollments.grade_level',
                'enrollments.strand_id',
                'students.sex',
                'enrollments.learner_type',
                DB::raw('count(*) as total'),
                DB::raw('sum(case when students.is_indigenous then 1 else 0 end) as indigenous'),
                DB::raw('sum(case when students.is_4ps_beneficiary then 1 else 0 end) as four_ps'),
            ])
            ->toBase()
            ->get();

        $strands = Strand::orderBy('sort_order')->get()->keyBy('id');
        $grades = [];

        foreach ($rows->groupBy('grade_level') as $grade => $gradeRows) {
            $strandRows = [];

            foreach ($strands as $strand) {
                $mine = $gradeRows->where('strand_id', $strand->id);
                if ($mine->isEmpty() && ! $strand->is_active) {
                    continue;
                }
                $strandRows[] = ['code' => $strand->code, 'name' => $strand->name, 'track' => $strand->track->value, ...$this->tally($mine)];
            }

            $byTrack = fn (Track $t) => $this->tally($gradeRows->whereIn('strand_id', $strands->where('track', $t)->keys()));

            $grades[] = [
                'grade_level' => (int) $grade,
                'label' => GradeLevel::from((int) $grade)->label(),
                'strands' => $strandRows,
                'tracks' => [
                    'academic' => $byTrack(Track::Academic),
                    'tvl' => $byTrack(Track::Tvl),
                ],
                'total' => $this->tally($gradeRows),
            ];
        }

        usort($grades, fn ($a, $b) => $a['grade_level'] <=> $b['grade_level']);

        $grade11OldStudents = collect($grades)->firstWhere('grade_level', 11)['total']['learner_types'][LearnerType::OldStudent->value] ?? 0;

        return [
            'grades' => $grades,
            'total' => $this->tally($rows),
            'cancelled' => Enrollment::where('school_year_id', $year->id)->where('status', EnrollmentStatus::Cancelled)->count(),
            'grade_10_completers' => $year->grade_10_completers,
            'grade_11_retention' => $year->grade_10_completers
                ? round($grade11OldStudents / $year->grade_10_completers, 4)
                : null,
            'learner_types' => LearnerType::options(),
        ];
    }

    /**
     * Learners encoded per day, like the DAILY_MONITORING tab. Cancelled
     * enrollments count too, because they were still encoded that day.
     * Imported rows with no encoding date are grouped last with a null date.
     *
     * @return list<array<string, mixed>>
     */
    public function daily(SchoolYear $year): array
    {
        return Enrollment::query()
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->where('enrollments.school_year_id', $year->id)
            ->groupBy('enrollments.encoded_on', 'enrollments.grade_level', 'students.sex')
            ->select(['enrollments.encoded_on', 'enrollments.grade_level', 'students.sex', DB::raw('count(*) as total')])
            ->toBase()
            ->get()
            ->groupBy(fn ($r) => substr((string) $r->encoded_on, 0, 10))
            ->sortKeysDesc()
            ->sortBy(fn ($day, string $date) => $date === '')
            ->map(function (Collection $day, string $date) {
                $grades = $day->groupBy('grade_level')->map(fn (Collection $g) => [
                    'male' => (int) $g->where('sex', 'male')->sum('total'),
                    'female' => (int) $g->where('sex', 'female')->sum('total'),
                    'total' => (int) $g->sum('total'),
                ]);

                return [
                    'date' => $date === '' ? null : $date,
                    'grades' => $grades->sortKeys()->all(),
                    'male' => (int) $day->where('sex', 'male')->sum('total'),
                    'female' => (int) $day->where('sex', 'female')->sum('total'),
                    'total' => (int) $day->sum('total'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function tally(Collection $rows): array
    {
        $types = [];
        foreach (LearnerType::cases() as $type) {
            $types[$type->value] = (int) $rows->where('learner_type', $type->value)->sum('total');
        }

        return [
            'male' => (int) $rows->where('sex', 'male')->sum('total'),
            'female' => (int) $rows->where('sex', 'female')->sum('total'),
            'total' => (int) $rows->sum('total'),
            'learner_types' => $types,
            'indigenous' => (int) $rows->sum('indigenous'),
            'four_ps' => (int) $rows->sum('four_ps'),
        ];
    }
}
