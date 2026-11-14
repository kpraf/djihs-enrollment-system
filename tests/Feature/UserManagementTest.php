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

        $response->assertSessionHas('success');
        preg_match('/Temporary password: (\S+)/', session('success'), $m);

        $user = User::where('username', 'ana.reyes')->firstOrFail();
        $this->assertTrue(Hash::check($m[1], $user->password));
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
        $this->actingAs($ict)->post("/users/{$admin->id}/toggle-active")->assertForbidden();
    }

    public function test_users_can_not_deactivate_themselves(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)->post("/users/{$admin->id}/toggle-active")->assertSessionHas('error');
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
}
