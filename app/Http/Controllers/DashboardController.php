<?php

namespace App\Http\Controllers;

use App\Enums\Ability;
use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\RevisionRequest;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Reports\EnrollmentSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One dashboard, with the blocks each role had on its legacy dashboard.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, EnrollmentSummary $summary): Response
    {
        $user = $request->user();
        $years = SchoolYear::orderByDesc('start_year')->get();
        $year = $years->firstWhere('id', $request->integer('school_year'))
            ?? $years->firstWhere('is_active', true)
            ?? $years->first();

        $canViewReports = $user->hasAbility(Ability::ViewReports);

        return Inertia::render('dashboard', [
            'schoolYears' => $years->map->only(['id', 'name', 'is_active'])->values(),
            'selectedYear' => $year?->only(['id', 'name']),
            // The enrollment tables load right after the page, with grey
            // placeholders meanwhile, so the role blocks show at once.
            'monitoring' => Inertia::defer(fn () => $year && $canViewReports ? $summary->monitoring($year) : null, 'monitoring'),
            'daily' => Inertia::defer(fn () => $year && $canViewReports ? $summary->daily($year) : [], 'monitoring'),
            'review' => $year && $user->hasAbility(Ability::ReviewEnrollments) ? $this->review($year, $user) : null,
            'mySections' => $year && $user->hasAbility(Ability::ViewOwnSection) ? $this->adviserSections($year, $user) : null,
            'sectionOverview' => $year && $user->hasAbility(Ability::ManageSections) ? $this->sectionOverview($year) : null,
            'teaching' => $year && $user->hasAbility(Ability::ViewOwnClasses) ? $this->teaching($year, $user) : null,
            'system' => $user->hasAbility(Ability::ManageUsers) && in_array($user->role, [Role::Admin, Role::IctCoordinator], true) ? $this->system() : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function review(SchoolYear $year, User $user): array
    {
        $pending = Enrollment::with(['student:id,last_name,first_name,middle_name,extension_name', 'strand:id,code'])
            ->where('school_year_id', $year->id)->where('status', EnrollmentStatus::Pending);

        return [
            'pending' => (clone $pending)->count(),
            'recent' => (clone $pending)->orderBy('encoded_on')->orderBy('id')->limit(5)->get()->map(fn (Enrollment $e) => [
                'id' => $e->id, 'name' => $e->student->full_name, 'grade_level' => $e->grade_level->value, 'strand' => $e->strand?->code, 'encoded_on' => $e->encoded_on?->toDateString(),
            ]),
            'revisions' => $user->hasAbility(Ability::EditEnrollments) ? RevisionRequest::where('status', RevisionRequest::PENDING)->count() : null,
            'transferees' => Enrollment::where('school_year_id', $year->id)->where('status', EnrollmentStatus::Enrolled)->where('learner_type', 'transferee')->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function adviserSections(SchoolYear $year, User $user): array
    {
        return Section::with('strand:id,code')->withCount(['enrollments' => fn ($q) => $q->where('status', EnrollmentStatus::Enrolled)])
            ->where('school_year_id', $year->id)->where('adviser_id', $user->id)->orderBy('name')->get()
            ->map(fn (Section $s) => ['id' => $s->id, 'label' => $s->label(), 'filled' => $s->enrollments_count, 'capacity' => $s->capacity])
            ->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionOverview(SchoolYear $year): array
    {
        $sections = Section::withCount(['enrollments' => fn ($q) => $q->where('status', EnrollmentStatus::Enrolled)])->where('school_year_id', $year->id)->get();
        $enrolled = Enrollment::where('school_year_id', $year->id)->where('status', EnrollmentStatus::Enrolled);

        return [
            'sections' => $sections->count(),
            'capacity' => $sections->sum('capacity'),
            'assigned' => (clone $enrolled)->whereNotNull('section_id')->count(),
            'unassigned' => (clone $enrolled)->whereNull('section_id')->count(),
            'without_adviser' => $sections->whereNull('adviser_id')->count(),
            'full' => $sections->filter(fn ($s) => $s->enrollments_count >= $s->capacity)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function teaching(SchoolYear $year, User $user): array
    {
        $classes = TeacherAssignment::with(['section' => fn ($q) => $q->withCount(['enrollments' => fn ($q) => $q->where('status', EnrollmentStatus::Enrolled)])])
            ->where('user_id', $user->id)->whereHas('section', fn ($q) => $q->where('school_year_id', $year->id))->get();

        return [
            'classes' => $classes->count(),
            'learners' => $classes->sum(fn ($c) => $c->section->enrollments_count),
            'by_subject' => $classes->groupBy('subject_name')->map(fn ($g, $name) => ['label' => $name, 'classes' => $g->count()])->values(),
            'by_grade' => $classes->groupBy(fn ($c) => $c->section->grade_level->value)->map(fn ($g, $grade) => ['label' => "Grade {$grade}", 'classes' => $g->count()])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function system(): array
    {
        $byRole = User::where('is_active', true)->groupBy('role')->select('role', DB::raw('count(*) as n'))->toBase()->pluck('n', 'role');

        return [
            'users' => User::count(),
            'active_users' => User::where('is_active', true)->count(),
            'roles' => array_map(fn (Role $r) => ['label' => $r->label(), 'count' => (int) ($byRole[$r->value] ?? 0)], Role::cases()),
            'activity_today' => AuditLog::where('created_at', '>=', now()->startOfDay())->whereNotIn('event', AuditTrail::ACCESS_EVENTS)->count(),
            'recent' => (new AuditTrail)->present(AuditLog::with('user:id,first_name,last_name')->whereNotIn('event', AuditTrail::ACCESS_EVENTS)->latest('id')->limit(8)->get()),
            // The privacy notice needs the school's Data Protection Officer.
            'privacy_contact_missing' => ! config('privacy.dpo_name') && ! config('privacy.dpo_email') && ! config('privacy.dpo_phone'),
        ];
    }
}
