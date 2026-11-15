<?php

namespace App\Services\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Exceptions\StaleRecordException;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every status change of an enrollment, in one place.
 *
 * Each change locks the row and checks the current status first, so two
 * reviewers clicking "Approve" on the same learner, or one approving while
 * another rejects, cannot both succeed.
 */
class EnrollmentWorkflow
{
    public function approve(Enrollment $enrollment, User $by): Enrollment
    {
        return $this->transition($enrollment->id, [EnrollmentStatus::Pending], function (Enrollment $e) use ($by) {
            $e->fill([
                'status' => EnrollmentStatus::Enrolled,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
            ]);
        });
    }

    public function reject(Enrollment $enrollment, User $by, string $reason): Enrollment
    {
        return $this->transition($enrollment->id, [EnrollmentStatus::Pending], function (Enrollment $e) use ($by, $reason) {
            $e->fill([
                'status' => EnrollmentStatus::Rejected,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'status_changed_by' => $by->id,
                'status_changed_at' => now(),
                'status_reason' => $reason,
            ]);
        });
    }

    /**
     * Cancelled, dropped or transferred out. The learner also leaves their
     * section, freeing the seat.
     */
    public function leave(Enrollment $enrollment, User $by, EnrollmentStatus $status, string $reason, int $expectedVersion): Enrollment
    {
        abort_unless(in_array($status, EnrollmentStatus::leaving(), true), 422);

        return $this->transition($enrollment->id, [EnrollmentStatus::Enrolled, EnrollmentStatus::Pending], function (Enrollment $e) use ($by, $status, $reason) {
            $e->fill([
                'status' => $status,
                'section_id' => null,
                'status_changed_by' => $by->id,
                'status_changed_at' => now(),
                'status_reason' => $reason,
            ]);
        }, $expectedVersion);
    }

    /**
     * Back to enrolled after leaving, or back to pending after a rejection.
     */
    public function restore(Enrollment $enrollment, User $by, int $expectedVersion): Enrollment
    {
        return $this->transition($enrollment->id, [EnrollmentStatus::Rejected, ...EnrollmentStatus::leaving()], function (Enrollment $e) use ($by) {
            $e->fill([
                'status' => $e->status === EnrollmentStatus::Rejected ? EnrollmentStatus::Pending : EnrollmentStatus::Enrolled,
                'status_changed_by' => $by->id,
                'status_changed_at' => now(),
                'status_reason' => null,
            ]);
        }, $expectedVersion);
    }

    /**
     * @param  list<EnrollmentStatus>  $from
     * @param  callable(Enrollment): void  $apply
     *
     * @throws StaleRecordException
     */
    private function transition(int $id, array $from, callable $apply, ?int $expectedVersion = null): Enrollment
    {
        return DB::transaction(function () use ($id, $from, $apply, $expectedVersion) {
            $e = Enrollment::with('reviewer:id,first_name,last_name', 'statusChanger:id,first_name,last_name')->lockForUpdate()->findOrFail($id);

            if ($expectedVersion !== null && $e->lock_version !== $expectedVersion) {
                throw new StaleRecordException;
            }

            if (! in_array($e->status, $from, true)) {
                $who = $e->statusChanger?->name ?? $e->reviewer?->name;
                throw new StaleRecordException('This enrollment is already '.strtolower($e->status->label()).($who ? " (by {$who})" : '').'. Nothing was changed.');
            }

            $apply($e);
            $e->save();

            return $e;
        });
    }
}
