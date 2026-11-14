<?php

namespace App\Services\Enrollment;

use App\Exceptions\StaleRecordException;
use App\Http\Requests\Enrollment\LearnerRequest;
use App\Models\Enrollment;
use App\Models\RevisionRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Advisers propose edits; a registrar or ICT coordinator approves them.
 * As in the legacy system, nothing changes until the proposal is approved.
 */
class RevisionService
{
    /** Fields stored on the enrollment rather than the student. */
    private const ENROLLMENT_FIELDS = ['grade_level', 'strand_id', 'learner_type', 'is_irregular', 'weight_kg', 'height_m', 'remarks'];

    /**
     * Records only the fields that actually differ. Returns null when the
     * form was saved without any change.
     */
    public function request(Enrollment $enrollment, LearnerRequest $request, string $justification): ?RevisionRequest
    {
        $enrollment->load('student');
        $proposed = [...$request->studentData(), ...$request->enrollmentData()];
        $changes = [];

        foreach ($proposed as $field => $new) {
            $current = in_array($field, self::ENROLLMENT_FIELDS, true) ? $enrollment->{$field} : $enrollment->student->{$field};
            $old = self::plain($current);
            $new = self::plain($new);
            if ($old !== $new) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        if ($changes === []) {
            return null;
        }

        return RevisionRequest::create([
            'enrollment_id' => $enrollment->id,
            'requested_by' => $request->user()->id,
            'changes' => $changes,
            'student_version' => $enrollment->student->lock_version,
            'enrollment_version' => $enrollment->lock_version,
            'justification' => $justification,
        ]);
    }

    /**
     * Applies the proposal. Refused if any of the fields it changes was
     * changed by someone else after it was submitted, so an approval never
     * silently overwrites newer information.
     */
    public function approve(RevisionRequest $revision, User $by, ?string $notes = null): void
    {
        DB::transaction(function () use ($revision, $by, $notes) {
            $revision = RevisionRequest::lockForUpdate()->findOrFail($revision->id);
            $this->ensurePending($revision);

            $enrollment = Enrollment::lockForUpdate()->findOrFail($revision->enrollment_id);
            $student = Student::lockForUpdate()->findOrFail($enrollment->student_id);

            $studentChanges = [];
            $enrollmentChanges = [];

            foreach ($revision->changes as $field => $change) {
                $isEnrollment = in_array($field, self::ENROLLMENT_FIELDS, true);
                $current = self::plain($isEnrollment ? $enrollment->{$field} : $student->{$field});

                if ($current !== $change['old']) {
                    throw new StaleRecordException('This learner\'s '.strtolower(AuditTrail::label($field)).' was changed after the request was made. Reject it and ask for a new request.');
                }

                $isEnrollment ? $enrollmentChanges[$field] = $change['new'] : $studentChanges[$field] = $change['new'];
            }

            if (isset($studentChanges['lrn']) && Student::where('lrn', $studentChanges['lrn'])->whereKeyNot($student->id)->exists()) {
                throw new StaleRecordException('The new LRN already belongs to another learner. Reject this request.');
            }

            if ($studentChanges) {
                $student->update($studentChanges);
            }
            if ($enrollmentChanges) {
                if (array_key_exists('grade_level', $enrollmentChanges) || array_key_exists('strand_id', $enrollmentChanges)) {
                    $enrollmentChanges['section_id'] = null;
                }
                $enrollment->update($enrollmentChanges);
            }

            $revision->update(['status' => RevisionRequest::APPROVED, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_notes' => $notes]);
        });
    }

    public function reject(RevisionRequest $revision, User $by, string $notes): void
    {
        DB::transaction(function () use ($revision, $by, $notes) {
            $revision = RevisionRequest::lockForUpdate()->findOrFail($revision->id);
            $this->ensurePending($revision);
            $revision->update(['status' => RevisionRequest::REJECTED, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_notes' => $notes]);
        });
    }

    /**
     * @param  Collection<int, RevisionRequest>  $revisions
     * @return list<array<string, mixed>>
     */
    public static function present(Collection $revisions): array
    {
        $trail = new AuditTrail;

        return $revisions->map(fn (RevisionRequest $r) => [
            'id' => $r->id,
            'enrollment_id' => $r->enrollment_id,
            'learner' => $r->relationLoaded('enrollment') ? $r->enrollment?->student?->full_name : null,
            'status' => $r->status,
            'requester' => $r->requester?->name,
            'reviewer' => $r->reviewer?->name,
            'justification' => $r->justification,
            'review_notes' => $r->review_notes,
            'created_at' => $r->created_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'changes' => $trail->changeList(
                array_map(fn ($c) => $c['old'], $r->changes),
                array_map(fn ($c) => $c['new'], $r->changes),
            ),
        ])->values()->all();
    }

    private function ensurePending(RevisionRequest $revision): void
    {
        if ($revision->status !== RevisionRequest::PENDING) {
            throw new StaleRecordException("This request was already {$revision->status}. Nothing was changed.");
        }
    }

    /**
     * Normalises a value so form input and stored data compare equal:
     * enums to their value, dates to Y-m-d, numbers to strings, "" to null.
     */
    private static function plain(mixed $value): string|bool|null
    {
        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value,
            is_numeric($value) && str_contains((string) $value, '.') => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'),
            default => (string) $value,
        };
    }
}
