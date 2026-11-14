<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    /** @var array<string, class-string> */
    private const TYPES = [
        'student' => Student::class,
        'enrollment' => Enrollment::class,
        'user' => User::class,
        'strand' => Strand::class,
        'school_year' => SchoolYear::class,
    ];

    public function index(Request $request, AuditTrail $trail): Response
    {
        $filters = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', array_keys(self::TYPES))],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $page = AuditLog::with('user:id,first_name,last_name')
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('auditable_type', self::TYPES[$t]))
            ->when($filters['user'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $today = now()->startOfDay();

        return Inertia::render('audit-log/index', [
            'logs' => [...$page->toArray(), 'data' => $trail->present($page->getCollection())],
            'filters' => $filters,
            'stats' => [
                'total' => AuditLog::count(),
                'today' => AuditLog::where('created_at', '>=', $today)->count(),
                'week' => AuditLog::where('created_at', '>=', $today->copy()->subDays(6))->count(),
                'month' => AuditLog::where('created_at', '>=', $today->copy()->subDays(29))->count(),
            ],
            'users' => User::orderBy('last_name')->get(['id', 'first_name', 'last_name'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]),
            'types' => [
                ['value' => 'student', 'label' => 'Learners'],
                ['value' => 'enrollment', 'label' => 'Enrollments'],
                ['value' => 'user', 'label' => 'User accounts'],
                ['value' => 'strand', 'label' => 'Strands'],
                ['value' => 'school_year', 'label' => 'School years'],
            ],
        ]);
    }
}
