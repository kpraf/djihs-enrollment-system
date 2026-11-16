<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\Reports\AnalyticsReport;
use App\Services\Reports\MetricsReport;
use App\Support\ExportName;
use App\Support\TableExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as DownloadResponse;

/**
 * One Reports page in place of the separate Analytics and Metrics pages,
 * organised around the questions the school asks: how many, where, how
 * it's changing, who the learners are, and what needs attention. The
 * DepEd rates (the old Metrics page) show only for those allowed to see them.
 */
class ReportsController extends Controller
{
    public function index(Request $request, AnalyticsReport $analytics, MetricsReport $metrics): Response
    {
        [$years, $year, $compare] = $this->years($request);
        $user = $request->user();

        $current = $year ? $analytics->forYear($year) : null;
        $previous = $compare ? $analytics->forYear($compare) : null;

        // The headline numbers come with the page. The table, the trends and
        // the "needs attention" list load right after it, each in its own
        // request, while the page shows grey placeholders in their place.
        return Inertia::render('reports/index', [
            'schoolYears' => $years->map->only(['id', 'name', 'is_active'])->values(),
            'filters' => ['school_year' => $year?->id, 'compare' => $compare?->id],
            'compareName' => $compare?->name,
            'summary' => $current ? ['now' => self::summary($current), 'before' => $previous ? self::summary($previous) : null] : null,
            'table' => Inertia::defer(fn () => $year ? $this->table($analytics, $year, $compare) : [], 'table'),
            'trends' => Inertia::defer(fn () => $analytics->trends(), 'trends'),
            'strandTrends' => Inertia::defer(fn () => $analytics->strandTrends(), 'trends'),
            'profile' => $current ? [
                'learner_types' => $current['by_learner_type'],
                'inclusion' => [
                    ['label' => 'Indigenous Peoples (IP)', 'total' => $current['overview']['indigenous']],
                    ['label' => '4Ps beneficiaries', 'total' => $current['overview']['four_ps']],
                    ['label' => 'Learners with disability', 'total' => $current['overview']['pwd']],
                ],
            ] : null,
            'attention' => Inertia::defer(fn () => $year ? $this->attention($year, $user, $metrics) : [], 'attention'),
            'rates' => Inertia::defer(fn () => $year && $user->hasAbility(Ability::ViewMetrics) ? $metrics->forYear($year) : null, 'attention'),
        ]);
    }

    /**
     * Enrolled learners by grade and strand, with the compared year's total
     * beside each row.
     *
     * @return list<array<string, mixed>>
     */
    private function table(AnalyticsReport $analytics, SchoolYear $year, ?SchoolYear $compare): array
    {
        $before = $compare ? collect($analytics->byGradeAndStrand($compare)) : collect();

        return collect($analytics->byGradeAndStrand($year))->map(fn ($r) => [
            ...$r,
            'before' => $compare ? (int) ($before->first(fn ($p) => $p['grade'] === $r['grade'] && $p['strand'] === $r['strand'])['total'] ?? 0) : null,
        ])->values()->all();
    }

    public function export(Request $request, AnalyticsReport $analytics, MetricsReport $metrics): DownloadResponse
    {
        [, $year] = $this->years($request);
        abort_if($year === null, 404);
        $r = $analytics->forYear($year);
        $s = self::summary($r);

        $sheets = [
            'Summary' => [['Measure', 'Value'], [
                ['Enrolled', $s['enrolled']], ['New to the school', $s['new']], ['Returning', $s['returning']],
                ['Male', $s['male']], ['Female', $s['female']],
            ]],
            'By grade and strand' => [['Grade', 'Strand', 'Male', 'Female', 'Total'],
                collect($analytics->byGradeAndStrand($year))->map(fn ($x) => ["Grade {$x['grade']}", $x['strand'] ?? '—', $x['male'], $x['female'], $x['total']])],
            'Type of learner' => [['Type of learner', 'Total'], collect($r['by_learner_type'])->map(fn ($t) => [$t['label'], $t['total']])],
            'Inclusion' => [['Group', 'Total'], [
                ['Indigenous Peoples (IP)', $r['overview']['indigenous']], ['4Ps beneficiaries', $r['overview']['four_ps']], ['Learners with disability', $r['overview']['pwd']],
            ]],
            'Trends' => [['School year', 'Total', 'Male', 'Female'], collect($analytics->trends())->map(fn ($t) => [$t['year'], $t['total'], $t['male'], $t['female']])],
        ];

        if ($request->user()->hasAbility(Ability::ViewMetrics)) {
            $m = $metrics->forYear($year);
            $pct = fn ($v) => $v === null ? 'n/a' : $v.'%';
            $sheets['Rates'] = [['Indicator', 'Value'], [
                ['Growth from '.($m['previous_year'] ?? 'previous year'), $pct($m['growth_rate'])],
                ['Transition rate (Grade 10 to 11)', $pct($m['transition_rate'])],
                ['Promotion rate', $pct($m['promotion_rate'])],
                ['Retention rate', $pct($m['retention_rate'])],
                ['Dropout rate', $pct($m['dropout_rate'])],
                ['Sections', $m['resources']['sections']],
                ['Learners per section', $m['resources']['learners_per_section']],
                ['Seat fill rate', $pct($m['resources']['fill_rate'])],
            ]];
            $sheets['Rates by grade'] = [['Grade', 'Enrolled', 'Promotion', 'Retention', 'Dropout'],
                collect($m['grades'])->map(fn ($g) => [$g['label'], $g['enrollment'], $pct($g['promotion_rate']), $pct($g['retention_rate']), $pct($g['dropout_rate'])])];
        }

        return TableExport::report($request->query('format') === 'csv' ? 'csv' : 'xlsx', ExportName::make('Enrollment_Report', ExportName::schoolYear($year->name)), $sheets);
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{enrolled: int, new: int, returning: int, male: int, female: int}
     */
    private static function summary(array $r): array
    {
        $types = collect($r['by_learner_type'])->pluck('total', 'type');
        $returning = (int) ($types['old_student'] ?? 0) + (int) ($types['repeater'] ?? 0);

        return [
            'enrolled' => $r['overview']['total'],
            'new' => $r['overview']['total'] - $returning,
            'returning' => $returning,
            'male' => $r['overview']['male'],
            'female' => $r['overview']['female'],
        ];
    }

    /**
     * Things staff can act on, each linking to the list behind it when the
     * user may open that page.
     *
     * @return list<array{label: string, count: int, href: ?string}>
     */
    private function attention(SchoolYear $year, User $user, MetricsReport $metrics): array
    {
        $resources = $metrics->resources($year);
        $sections = $user->hasAbility(Ability::ManageSections);

        return [
            [
                'label' => 'Waiting for review',
                'count' => Enrollment::where('school_year_id', $year->id)->where('status', EnrollmentStatus::Pending)->count(),
                'href' => $user->hasAbility(Ability::ReviewEnrollments) ? route('reviews.index') : null,
            ],
            [
                'label' => 'Enrolled with incomplete documents',
                'count' => Enrollment::where('school_year_id', $year->id)->where('status', EnrollmentStatus::Enrolled)->documentsIncomplete()->count(),
                'href' => $user->hasAbility(Ability::ViewEnrollments) ? route('enrollments.index', ['school_year' => $year->id, 'documents' => 'incomplete']) : null,
            ],
            ['label' => 'Enrolled but not yet in a section', 'count' => $resources['unassigned_learners'], 'href' => $sections ? route('section-assignments.index') : null],
            ['label' => 'Sections without an adviser', 'count' => $resources['without_adviser'], 'href' => $sections ? route('sections.index') : null],
            ['label' => 'Sections at least 90% full', 'count' => $resources['nearly_full'], 'href' => $sections ? route('sections.index') : null],
        ];
    }

    /**
     * The selected school year (default: the active one) and the year it is
     * compared with (default: the year before it).
     *
     * @return array{0: Collection<int, SchoolYear>, 1: ?SchoolYear, 2: ?SchoolYear}
     */
    private function years(Request $request): array
    {
        $years = SchoolYear::orderByDesc('start_year')->get();
        $year = $years->firstWhere('id', $request->integer('school_year')) ?? $years->firstWhere('is_active', true) ?? $years->first();
        $compare = $request->filled('compare')
            ? $years->firstWhere('id', $request->integer('compare'))
            : ($year ? $years->firstWhere('start_year', $year->start_year - 1) : null);

        return [$years, $year, $compare?->is($year) ? null : $compare];
    }
}
