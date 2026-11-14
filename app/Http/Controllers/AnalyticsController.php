<?php

namespace App\Http\Controllers;

use App\Enums\GradeLevel;
use App\Models\SchoolYear;
use App\Services\Reports\AnalyticsReport;
use App\Support\TableExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as DownloadResponse;

class AnalyticsController extends Controller
{
    public function index(Request $request, AnalyticsReport $report): Response
    {
        [$years, $year, $grade] = $this->filters($request);
        $compareA = $years->firstWhere('id', $request->integer('compare_a'));
        $compareB = $years->firstWhere('id', $request->integer('compare_b'));

        return Inertia::render('analytics/index', [
            'schoolYears' => $years->map->only(['id', 'name', 'is_active'])->values(),
            'filters' => ['school_year' => $year?->id, 'grade' => $grade, 'compare_a' => $compareA?->id, 'compare_b' => $compareB?->id],
            'grades' => GradeLevel::options(),
            'report' => $year ? $report->forYear($year, $grade) : null,
            'trends' => $report->trends($grade),
            'comparison' => $compareA && $compareB ? $report->compare($compareA, $compareB) : null,
        ]);
    }

    public function export(Request $request, AnalyticsReport $report): DownloadResponse
    {
        [, $year, $grade] = $this->filters($request);
        abort_if($year === null, 404);
        $r = $report->forYear($year, $grade);
        $cols = fn (array $row) => [$row['total'], $row['male'], $row['female'], $row['pwd'], $row['balik_aral'], $row['transferee'], $row['indigenous'], $row['four_ps']];
        $headers = ['Total', 'Male', 'Female', 'PWD', 'Balik-Aral', 'Transferee', 'IP', '4Ps'];

        return TableExport::report($request->query('format') === 'csv' ? 'csv' : 'xlsx', "Analytics_{$year->name}", [
            'By grade' => [['Grade Level', ...$headers], collect($r['by_grade'])->map(fn ($g) => [$g['label'], ...$cols($g)])],
            'By strand' => [['Strand', 'Name', ...$headers], collect($r['by_strand'])->map(fn ($s) => [$s['code'], $s['name'], ...$cols($s)])],
            'By learner type' => [['Type of Learner', 'Total'], collect($r['by_learner_type'])->map(fn ($t) => [$t['label'], $t['total']])],
            'Trends' => [['School Year', 'Total', 'Male', 'Female'], collect($report->trends($grade))->map(fn ($t) => [$t['year'], $t['total'], $t['male'], $t['female']])],
        ]);
    }

    /**
     * @return array{0: Collection<int, SchoolYear>, 1: ?SchoolYear, 2: ?int}
     */
    private function filters(Request $request): array
    {
        $years = SchoolYear::orderByDesc('start_year')->get();
        $year = $years->firstWhere('id', $request->integer('school_year')) ?? $years->firstWhere('is_active', true) ?? $years->first();
        $grade = GradeLevel::tryFrom($request->integer('grade'))?->value;

        return [$years, $year, $grade];
    }
}
