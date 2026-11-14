<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Models\SchoolYear;
use App\Services\Reports\EnrollmentSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, EnrollmentSummary $summary): Response
    {
        $years = SchoolYear::orderByDesc('start_year')->get();
        $year = $request->integer('school_year')
            ? $years->firstWhere('id', $request->integer('school_year'))
            : ($years->firstWhere('is_active', true) ?? $years->first());

        $canViewReports = $request->user()->hasAbility(Ability::ViewReports);

        return Inertia::render('dashboard', [
            'schoolYears' => $years->map->only(['id', 'name', 'is_active'])->values(),
            'selectedYear' => $year?->only(['id', 'name']),
            'monitoring' => $year && $canViewReports ? $summary->monitoring($year) : null,
            'daily' => $year && $canViewReports ? $summary->daily($year) : [],
        ]);
    }
}
