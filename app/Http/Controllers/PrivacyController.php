<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\Enrollment;
use App\Services\Audit\AuditTrail;
use App\Services\Privacy\AccessLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Data Privacy Act (RA 10173) pages: the privacy notice given to parents,
 * the confidentiality agreement staff accept, and a learner's own data
 * record for requests under the right of access.
 */
class PrivacyController extends Controller
{
    public function notice(): Response
    {
        return Inertia::render('privacy/notice', ['privacy' => self::details()]);
    }

    public function agreement(Request $request): Response|RedirectResponse
    {
        if ($request->user()->privacy_agreed_at !== null) {
            return to_route('dashboard');
        }

        return Inertia::render('privacy/agreement', ['privacy' => self::details()]);
    }

    public function agree(Request $request): RedirectResponse
    {
        $request->validate(['agree' => ['accepted']], ['agree.accepted' => 'Tick the box to accept the agreement.']);
        $user = $request->user();

        if ($user->privacy_agreed_at === null) {
            $user->update(['privacy_agreed_at' => now()]);
        }

        return to_route('dashboard')->with('success', 'Thank you. You can now use the system.');
    }

    /**
     * Everything the system holds about one learner, printable, for a
     * parent or adult learner who asks to see their data.
     */
    public function record(Request $request, Enrollment $enrollment): Response
    {
        $student = $enrollment->student;
        $enrollments = $student->enrollments()
            ->with(['schoolYear', 'strand', 'section', 'documents', 'encoder:id,first_name,last_name', 'privacyNoticeGiver:id,first_name,last_name'])
            ->get()
            ->sortByDesc(fn (Enrollment $e) => $e->schoolYear->start_year);

        AccessLog::exported($request, $enrollment, 'Learner data record (printed or saved)');

        $hidden = ['lock_version'];
        $fields = collect($student->getFillable())
            ->reject(fn ($f) => in_array($f, $hidden, true))
            ->map(fn ($f) => [
                'label' => AuditTrail::label($f),
                'value' => self::value($student->getAttribute($f)),
            ])->values();

        return Inertia::render('privacy/record', [
            'privacy' => self::details(),
            'generatedAt' => now()->toIso8601String(),
            'generatedBy' => $request->user()->name,
            'learner' => ['name' => $student->full_name, 'lrn' => $student->lrn, 'fields' => $fields],
            'enrollments' => $enrollments->map(fn (Enrollment $e) => [
                'school_year' => $e->schoolYear->name,
                'grade' => $e->grade_level->value,
                'strand' => $e->strand?->code,
                'section' => $e->section?->name,
                'learner_type' => $e->learner_type->label(),
                'status' => $e->status->label(),
                'encoded' => trim(($e->encoded_on?->toDateString() ?? '').' '.($e->encoder ? 'by '.$e->encoder->name : '')),
                'privacy_notice' => $e->privacy_notice_at
                    ? $e->privacy_notice_at->toDateString().($e->privacyNoticeGiver ? ' (recorded by '.$e->privacyNoticeGiver->name.')' : '')
                    : 'Not recorded',
                'documents' => $e->documents->where('is_submitted', true)->map(fn ($d) => $d->document_type->label())->values(),
                'weight_kg' => $e->weight_kg,
                'height_m' => $e->height_m,
            ])->values(),
            'changes' => AuditTrail::forLearner($student, access: false),
            'access' => AuditTrail::forLearner($student, access: true),
            'documentTypes' => count(DocumentType::cases()),
        ]);
    }

    /** @return array<string, ?string> */
    public static function details(): array
    {
        return [
            'school_name' => config('privacy.school_name'),
            'school_address' => config('privacy.school_address'),
            'dpo_name' => config('privacy.dpo_name'),
            'dpo_email' => config('privacy.dpo_email'),
            'dpo_phone' => config('privacy.dpo_phone'),
            'retention' => config('privacy.retention'),
        ];
    }

    private static function value(mixed $v): ?string
    {
        return match (true) {
            $v === null || $v === '' => null,
            is_bool($v) => $v ? 'Yes' : 'No',
            $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
            $v instanceof \BackedEnum => method_exists($v, 'label') ? $v->label() : (string) $v->value,
            default => (string) $v,
        };
    }
}
