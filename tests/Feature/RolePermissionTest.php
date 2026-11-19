<?php

namespace Tests\Feature;

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\RolePermission;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SchoolYear::factory()->active()->create();
        $this->admin = User::factory()->role(Role::Admin)->create();
    }

    private function change(string $role, Ability $ability, bool $granted, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->put('/roles', ['changes' => [['role' => $role, 'ability' => $ability->value, 'granted' => $granted]]]);
    }

    public function test_every_role_starts_with_its_old_permissions(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertSame($role->defaultAbilities(), $role->abilities(), "{$role->label()} keeps what it had.");
        }
        $this->assertTrue(Role::Admin->can(Ability::ManageRoles));
        $this->assertFalse(Role::IctCoordinator->can(Ability::ManageRoles), 'Only the Administrator manages permissions by default.');
    }

    public function test_only_people_with_the_permission_can_open_or_change_it(): void
    {
        $this->actingAs($this->admin)->get('/roles')->assertOk()
            ->assertInertia(fn ($page) => $page->component('roles/index')->has('roles', count(Role::cases()))->has('abilities', count(Ability::cases())));

        $registrar = User::factory()->role(Role::Registrar)->create();
        $this->actingAs($registrar)->get('/roles')->assertForbidden();
        $this->change('registrar', Ability::ManageSections, true, $registrar)->assertForbidden();
        $this->assertFalse(Role::Registrar->can(Ability::ManageSections));
    }

    public function test_giving_and_taking_away_a_permission_changes_what_people_can_open(): void
    {
        $registrar = User::factory()->role(Role::Registrar)->create();
        $keyTeacher = User::factory()->role(Role::KeyTeacher)->create();
        $this->actingAs($registrar)->get('/sections')->assertForbidden();

        // The Key Teacher's work moves to the Registrar.
        $this->change('registrar', Ability::ManageSections, true)->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved 1 change.');
        $this->change('key_teacher', Ability::ManageSections, false)->assertSessionHasNoErrors();

        $this->actingAs($registrar)->get('/sections')->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.abilities', fn ($a) => collect($a)->contains('manage-sections')));
        $this->actingAs($keyTeacher)->get('/sections')->assertForbidden();
    }

    public function test_each_change_is_in_the_audit_log_in_plain_words(): void
    {
        $this->change('registrar', Ability::ManageSections, true);
        $this->change('registrar', Ability::ManageSections, false);

        $entries = (new AuditTrail)->present(AuditLog::with('user')->where('auditable_type', (new RolePermission)->getMorphClass())->latest('id')->get());
        $this->assertCount(2, $entries);
        $this->assertSame(['Permission taken away', 'Permission given'], array_column($entries, 'detail'));
        $this->assertSame('Registrar: Manage sections and strands, and place learners in sections', $entries[0]['subject']);
        $this->assertSame($this->admin->name, $entries[0]['user']);
    }

    public function test_the_administrator_cannot_be_locked_out(): void
    {
        $this->change('admin', Ability::ManageRoles, false)->assertSessionHasErrors('changes');
        $this->change('admin', Ability::ManageUsers, false)->assertSessionHasErrors('changes');

        $this->assertTrue(Role::Admin->can(Ability::ManageRoles));
        $this->actingAs($this->admin)->get('/roles')->assertOk();
        $this->actingAs($this->admin)->post('/roles/admin/retire')->assertForbidden();
    }

    public function test_only_the_cells_that_changed_are_saved(): void
    {
        // Two administrators save different changes from pages loaded at the same time.
        $other = User::factory()->role(Role::Admin)->create();
        $this->change('registrar', Ability::ManageSections, true);
        $this->change('ict_coordinator', Ability::ViewAnalytics, true, $other);

        $this->assertTrue(Role::Registrar->can(Ability::ManageSections), "The second save didn't undo the first.");
        $this->assertTrue(Role::IctCoordinator->can(Ability::ViewAnalytics));
        $this->change('registrar', Ability::ManageSections, true)->assertSessionHas('success', 'Nothing to change: those permissions were already set.');
    }

    public function test_retiring_a_role_moves_its_accounts_and_hides_it_from_new_accounts(): void
    {
        $keyTeachers = User::factory()->count(2)->role(Role::KeyTeacher)->create();

        $this->actingAs($this->admin)->post('/roles/key_teacher/retire')->assertSessionHasErrors('move_to');
        $this->actingAs($this->admin)->post('/roles/key_teacher/retire', ['move_to' => 'admin'])->assertSessionHasErrors('move_to');

        $this->actingAs($this->admin)->post('/roles/key_teacher/retire', ['move_to' => 'registrar'])
            ->assertSessionHas('success', 'Key Teacher is retired. 2 accounts moved to Registrar.');

        $this->assertTrue(Role::KeyTeacher->isRetired());
        $this->assertSame([Role::Registrar, Role::Registrar], $keyTeachers->map(fn ($u) => $u->fresh()->role)->all());
        $this->actingAs($this->admin)->get('/users')->assertInertia(fn ($page) => $page->where('roles', fn ($roles) => ! collect($roles)->contains('value', 'key_teacher')));
        $this->actingAs($this->admin)->post('/users', ['username' => 'new.kt', 'first_name' => 'A', 'last_name' => 'B', 'role' => 'key_teacher'])
            ->assertSessionHasErrors('role');
        $this->change('key_teacher', Ability::ViewEnrollments, false)->assertSessionHasErrors('changes');

        // Each move is in the audit log as a change of role.
        $this->assertSame(2, AuditLog::where('auditable_type', (new User)->getMorphClass())->where('event', 'updated')
            ->whereIn('auditable_id', $keyTeachers->pluck('id'))->count());

        $this->actingAs($this->admin)->post('/roles/key_teacher/restore')->assertSessionHas('success');
        $this->assertFalse(Role::KeyTeacher->isRetired());
    }

    public function test_reset_puts_every_role_back_to_its_defaults(): void
    {
        $this->change('registrar', Ability::ManageSections, true);
        $this->change('adviser', Ability::EncodeEnrollments, false);

        $this->actingAs($this->admin)->post('/roles/reset')->assertSessionHas('success', 'Default permissions are back.');

        foreach (Role::cases() as $role) {
            $this->assertSame($role->defaultAbilities(), $role->abilities());
        }
    }
}
