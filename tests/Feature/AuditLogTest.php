<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Strand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_are_recorded_with_who_made_them(): void
    {
        $enrollment = Enrollment::factory()->create();
        $newStrand = Strand::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);

        $enrollment->update(['strand_id' => $newStrand->id]);

        $log = AuditLog::where('auditable_type', Enrollment::class)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(['strand_id' => $newStrand->id], $log->new_values);
        $this->assertArrayHasKey('strand_id', $log->old_values);
    }

    public function test_passwords_never_reach_the_audit_log(): void
    {
        $user = User::factory()->create();
        $user->update(['password' => 'another-secret1']);

        $this->assertFalse(AuditLog::where('auditable_type', User::class)->get()->contains(
            fn (AuditLog $log) => array_key_exists('password', $log->new_values ?? []) || array_key_exists('password', $log->old_values ?? []),
        ));
    }
}
