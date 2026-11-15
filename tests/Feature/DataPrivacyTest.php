<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Strand;
use App\Models\User;
use App\Services\Privacy\AccessLog;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LearnerPayload;
use Tests\TestCase;

/**
 * Data Privacy Act (RA 10173) features: the privacy notice, the staff
 * confidentiality agreement, logging who saw or downloaded personal data,
 * and a learner's own data record.
 */
class DataPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;

    private SchoolYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StrandSeeder::class);
        $this->year = SchoolYear::factory()->active()->create();
        $this->registrar = User::factory()->role(Role::Registrar)->create();
    }

    private function enrolled(): Enrollment
    {
        return Enrollment::factory()->enrolled()->create(['school_year_id' => $this->year->id, 'grade_level' => 11, 'strand_id' => Strand::first()->id]);
    }

    public function test_the_privacy_notice_is_public_and_shows_the_dpo(): void
    {
        config(['privacy.dpo_name' => 'Ma. Teresa Cruz', 'privacy.dpo_email' => 'dpo@example.edu.ph']);

        $this->get('/privacy')->assertOk()->assertInertia(fn ($p) => $p->component('privacy/notice')
            ->where('privacy.dpo_name', 'Ma. Teresa Cruz')
            ->where('privacy.dpo_email', 'dpo@example.edu.ph'));
    }

    public function test_an_enrollment_needs_the_privacy_notice_and_records_who_gave_it(): void
    {
        $this->actingAs($this->registrar)->post('/enrollments', LearnerPayload::make(['privacy_notice_given' => false]))
            ->assertSessionHasErrors('privacy_notice_given');
        $this->assertSame(0, Enrollment::count());

        $this->travelTo(now()->setTime(9, 30));
        $this->actingAs($this->registrar)->post('/enrollments', LearnerPayload::make())->assertSessionHasNoErrors();

        $e = Enrollment::sole();
        $this->assertSame($this->registrar->id, $e->privacy_notice_by);
        $this->assertSame(now()->toDateTimeString(), $e->privacy_notice_at->toDateTimeString());
    }

    public function test_new_staff_must_accept_the_confidentiality_agreement_first(): void
    {
        $adviser = User::factory()->role(Role::Adviser)->privacyNotAgreed()->create();
        $e = $this->enrolled();

        $this->actingAs($adviser)->get('/dashboard')->assertRedirect('/privacy/agreement');
        $this->actingAs($adviser)->get("/enrollments/{$e->id}")->assertRedirect('/privacy/agreement');
        $this->actingAs($adviser)->get('/privacy')->assertOk();
        $this->actingAs($adviser)->get('/privacy/agreement')->assertInertia(fn ($p) => $p->component('privacy/agreement'));

        $this->actingAs($adviser)->post('/privacy/agreement', ['agree' => false])->assertSessionHasErrors('agree');
        $this->assertNull($adviser->fresh()->privacy_agreed_at);

        $this->actingAs($adviser)->post('/privacy/agreement', ['agree' => true])->assertRedirect('/dashboard');
        $this->assertNotNull($adviser->fresh()->privacy_agreed_at);
        $this->actingAs($adviser)->get('/dashboard')->assertOk();

        $log = AuditLog::where('auditable_type', User::class)->where('auditable_id', $adviser->id)->where('event', 'updated')->latest('id')->first();
        $this->assertArrayHasKey('privacy_agreed_at', $log->new_values, 'Accepting the agreement is recorded in the audit log.');
    }

    public function test_a_password_change_comes_before_the_agreement(): void
    {
        $user = User::factory()->mustChangePassword()->privacyNotAgreed()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.edit'));
        $this->actingAs($user)->get('/privacy/agreement')->assertRedirect(route('password.edit'));
        // Found by the browser tests: the two steps used to redirect to each other forever.
        $this->actingAs($user)->get(route('password.edit'))->assertOk();

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'password', 'password' => 'Brand-new-pass1', 'password_confirmation' => 'Brand-new-pass1',
        ])->assertSessionHasNoErrors();
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/privacy/agreement');
        $this->actingAs($user->fresh())->get('/privacy/agreement')->assertOk();
    }

    public function test_viewing_a_learner_is_logged_once_per_half_hour(): void
    {
        $e = $this->enrolled();

        $this->actingAs($this->registrar)->get("/enrollments/{$e->id}")->assertOk();
        $this->actingAs($this->registrar)->get("/enrollments/{$e->id}")->assertOk();
        $this->assertSame(1, AuditLog::where('event', AccessLog::VIEWED)->count(), 'Refreshing the page does not flood the log.');

        $this->travel(31)->minutes();
        $this->actingAs($this->registrar)->get("/enrollments/{$e->id}")->assertOk();
        $this->assertSame(2, AuditLog::where('event', AccessLog::VIEWED)->count());

        $view = AuditLog::where('event', AccessLog::VIEWED)->first();
        $this->assertSame($this->registrar->id, $view->user_id);
        $this->assertSame(['what' => 'Learner record'], $view->new_values);

        // The learner page's history shows changes, not views.
        $this->actingAs($this->registrar)->get("/enrollments/{$e->id}")
            ->assertInertia(fn ($p) => $p->where('history', fn ($h) => collect($h)->every(fn ($row) => $row['event'] !== 'viewed')));
    }

    public function test_every_download_of_personal_data_is_logged(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $adviser = User::factory()->role(Role::Adviser)->create();
        $section = Section::create(['school_year_id' => $this->year->id, 'grade_level' => 11, 'strand_id' => Strand::first()->id, 'name' => 'Hope', 'capacity' => 40, 'adviser_id' => $adviser->id]);

        $this->actingAs($adviser)->get("/sections/{$section->id}/export/csv")->assertOk();
        $this->actingAs($adviser)->get("/sections/{$section->id}/sf1")->assertOk();
        $this->actingAs($adviser)->get("/sections/{$section->id}/print")->assertOk();
        $this->actingAs($admin)->get('/backup')->assertOk();
        $this->actingAs($admin)->get('/users/export')->assertOk();
        $this->actingAs($admin)->get('/audit-log/export')->assertOk();

        $this->assertEqualsCanonicalizing(
            ['Class list (CSV)', 'School Form 1 (SF1)', 'Printable class list', 'Full backup of every table (ZIP)', 'User accounts list (CSV)', 'Audit log (CSV)'],
            AuditLog::where('event', AccessLog::EXPORTED)->get()->pluck('new_values.what')->all(),
        );

        $this->actingAs($admin)->get('/audit-log?activity=access')->assertInertia(fn ($p) => $p
            ->has('logs.data', 6)
            ->where('logs.data.0.event', 'exported')
            ->where('logs.data.0.detail', 'Audit log (CSV)'));
        $this->actingAs($admin)->get('/audit-log?activity=changes')
            ->assertInertia(fn ($p) => $p->where('logs.data', fn ($rows) => collect($rows)->every(fn ($r) => ! in_array($r['event'], ['viewed', 'exported'], true))));
    }

    public function test_a_learner_data_record_shows_everything_held_and_who_saw_it(): void
    {
        $e = $this->enrolled();
        $e->student->update(['religion' => 'Iglesia ni Cristo']);
        $this->actingAs($this->registrar)->get("/enrollments/{$e->id}");

        $this->actingAs($this->registrar)->get("/enrollments/{$e->id}/data-record")->assertOk()
            ->assertInertia(fn ($p) => $p->component('privacy/record')
                ->where('learner.lrn', $e->student->lrn)
                ->where('learner.fields', fn ($f) => collect($f)->contains(fn ($row) => $row['label'] === 'Religion' && $row['value'] === 'Iglesia ni Cristo'))
                ->where('learner.fields', fn ($f) => collect($f)->doesntContain(fn ($row) => $row['label'] === 'Lock version'))
                ->has('enrollments', 1)
                ->where('access', fn ($a) => collect($a)->contains(fn ($row) => $row['event'] === 'viewed' && $row['detail'] === 'Learner record'))
                ->where('changes', fn ($c) => collect($c)->contains(fn ($row) => $row['event'] === 'updated')));

        $this->assertTrue(AuditLog::where('event', AccessLog::EXPORTED)->where('auditable_id', $e->id)->exists());

        $subject = User::factory()->role(Role::SubjectTeacher)->create();
        $this->actingAs($subject)->get("/enrollments/{$e->id}/data-record")->assertForbidden();
    }

    public function test_staff_are_signed_out_after_30_idle_minutes_by_default(): void
    {
        $saved = [$_ENV['SESSION_LIFETIME'] ?? null, $_SERVER['SESSION_LIFETIME'] ?? null, getenv('SESSION_LIFETIME')];
        unset($_ENV['SESSION_LIFETIME'], $_SERVER['SESSION_LIFETIME']);
        putenv('SESSION_LIFETIME');

        try {
            $this->assertSame(30, (require base_path('config/session.php'))['lifetime']);
        } finally {
            [$env, $server, $put] = $saved;
            $env === null ?: $_ENV['SESSION_LIFETIME'] = $env;
            $server === null ?: $_SERVER['SESSION_LIFETIME'] = $server;
            $put === false ?: putenv("SESSION_LIFETIME={$put}");
        }

        $this->assertStringContainsString('SESSION_LIFETIME=30', file_get_contents(base_path('.env.example')));
        $this->assertMatchesRegularExpression('/SESSION_LIFETIME\s+value: "30"/', file_get_contents(base_path('render.yaml')));
    }

    public function test_admins_are_warned_when_the_notice_has_no_dpo(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        config(['privacy.dpo_name' => null, 'privacy.dpo_email' => null, 'privacy.dpo_phone' => null]);
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($p) => $p->where('system.privacy_contact_missing', true));

        config(['privacy.dpo_email' => 'dpo@example.edu.ph']);
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($p) => $p->where('system.privacy_contact_missing', false));
    }
}
