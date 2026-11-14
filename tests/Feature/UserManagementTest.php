<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_account_shows_a_temporary_password_once(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $response = $this->actingAs($admin)->post('/users', [
            'username' => 'Ana.Reyes',
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => Role::Registrar->value,
        ]);

        $response->assertSessionHas('credential');
        $password = session('credential')['password'];

        $user = User::where('username', 'ana.reyes')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->must_change_password);
    }

    public function test_only_administrators_can_create_administrators(): void
    {
        $ict = User::factory()->role(Role::IctCoordinator)->create();

        $this->actingAs($ict)->post('/users', [
            'username' => 'sneaky',
            'first_name' => 'A',
            'last_name' => 'B',
            'role' => Role::Admin->value,
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['username' => 'sneaky']);
    }

    public function test_ict_coordinators_can_not_touch_administrator_accounts(): void
    {
        $ict = User::factory()->role(Role::IctCoordinator)->create();
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($ict)->post("/users/{$admin->id}/reset-password")->assertForbidden();
        $this->actingAs($ict)->put("/users/{$admin->id}/active", ['active' => false])->assertForbidden();
    }

    public function test_users_can_not_deactivate_themselves(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)->put("/users/{$admin->id}/active", ['active' => false])->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_the_last_administrator_keeps_the_admin_role(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)->put("/users/{$admin->id}", [
            'first_name' => 'A',
            'last_name' => 'B',
            'role' => Role::Registrar->value,
        ])->assertSessionHas('error');

        $this->assertSame(Role::Admin, $admin->fresh()->role);
    }

    public function test_resetting_a_password_forces_a_change(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->post("/users/{$user->id}/reset-password")->assertSessionHas('success');

        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertFalse(Hash::check('password', $user->fresh()->password));
    }

    public function test_usernames_are_unique_regardless_of_case(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        User::factory()->create(['username' => 'ana.reyes']);

        $this->actingAs($admin)->post('/users', [
            'username' => 'Ana.Reyes',
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'role' => Role::Registrar->value,
        ])->assertSessionHasErrors('username');

        $this->assertSame(2, User::count());
    }

    public function test_activation_sets_the_requested_state_so_double_clicks_do_not_undo_it(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $user = User::factory()->create();

        // Two staff click "Deactivate" at nearly the same moment.
        $this->actingAs($admin)->put("/users/{$user->id}/active", ['active' => false]);
        $this->actingAs($admin)->put("/users/{$user->id}/active", ['active' => false]);

        $this->assertFalse($user->fresh()->is_active);

        $this->actingAs($admin)->put("/users/{$user->id}/active", ['active' => true]);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_the_last_active_administrator_cannot_be_deactivated_by_another_admin(): void
    {
        $a = User::factory()->role(Role::Admin)->create();
        $b = User::factory()->role(Role::Admin)->create();

        $this->actingAs($a)->put("/users/{$b->id}/active", ['active' => false])->assertSessionHas('success');
        $this->actingAs($b->fresh())->put("/users/{$a->id}/active", ['active' => false])->assertRedirect(route('login'));

        $this->assertTrue($a->fresh()->is_active, 'A deactivated admin is signed out and cannot act.');
    }

    public function test_temporary_passwords_are_not_put_in_the_toast(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->post("/users/{$user->id}/reset-password");

        $this->assertStringNotContainsString(session('credential')['password'], session('success'));
    }
}
