<?php

namespace App\Http\Controllers;

use App\Models\SchoolYear;
use App\Services\Reports\MetricsReport;
use App\Support\TableExport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as DownloadResponse;

class MetricsController extends Controller
{
    public function index(Request $request, MetricsReport $report): Response
    {
        $years = SchoolYear::orderByDesc('start_year')->get();
        $year = $years->firstWhere('id', $request->integer('school_year')) ?? $years->firstWhere('is_active', true) ?? $years->first();

        return Inertia::render('metrics/index', [
            'schoolYears' => $years->map->only(['id', 'name', 'is_active'])->values(),
            'selectedYear' => $year?->id,
            'metrics' => $year ? $report->forYear($year) : null,
        ]);
    }

    public function export(Request $request, MetricsReport $report): DownloadResponse
    {
        $year = SchoolYear::find($request->integer('school_year')) ?? SchoolYear::active();
        abort_if($year === null, 404);
        $m = $report->forYear($year);
        $pct = fn ($v) => $v === null ? 'n/a' : $v.'%';
        $r = $m['resources'];

        return TableExport::report($request->query('format') === 'csv' ? 'csv' : 'xlsx', "Metrics_{$year->name}", [
            'Summary' => [['Indicator', 'Value'], [
                ['Total enrollment', $m['total_enrollment']],
                ['Growth from '.($m['previous_year'] ?? 'previous year'), $pct($m['growth_rate'])],
                ['Transition rate (G10 to G11)', $pct($m['transition_rate'])],
                ['Promotion rate', $pct($m['promotion_rate'])],
                ['Retention rate', $pct($m['retention_rate'])],
                ['Dropout rate', $pct($m['dropout_rate'])],
                ['Sections', $r['sections']],
                ['Learners per section', $r['learners_per_section']],
                ['Seat fill rate', $pct($r['fill_rate'])],
                ['Sections without adviser', $r['without_adviser']],
                ['Learners not in a section', $r['unassigned_learners']],
            ]],
            'By grade' => [['Grade Level', 'Enrollment', 'Promotion Rate', 'Retention Rate', 'Dropout Rate', 'Status'],
                collect($m['grades'])->map(fn ($g) => [$g['label'], $g['enrollment'], $pct($g['promotion_rate']), $pct($g['retention_rate']), $pct($g['dropout_rate']), ucfirst($g['status'])])],
            'Trends' => [['School Year', 'Enrollment', 'Dropout Rate'], collect($m['trends'])->map(fn ($t) => [$t['year'], $t['enrollment'], $pct($t['dropout_rate'])])],
            'Insights' => [['Insight'], collect($m['insights'])->map(fn ($i) => [$i['text']])],
        ]);
    }
}
