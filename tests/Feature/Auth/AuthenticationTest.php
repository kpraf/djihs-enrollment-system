<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_users_sign_in_with_their_username(): void
    {
        $user = User::factory()->create(['username' => 'juan.delacruz']);

        $response = $this->post('/login', ['username' => 'Juan.DelaCruz ', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_users_can_not_sign_in_with_a_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['username' => $user->username, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['username' => trans('auth.failed')]);

        $this->assertGuest();
    }

    public function test_deactivated_users_can_not_sign_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['username' => $user->username, 'password' => 'password'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_deactivated_message_is_not_shown_for_a_wrong_password(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['username' => $user->username, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['username' => trans('auth.failed')]);
    }

    public function test_a_user_deactivated_mid_session_is_signed_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_logins_are_rate_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $_) {
            $this->post('/login', ['username' => $user->username, 'password' => 'wrong-password']);
        }

        $response = $this->post('/login', ['username' => $user->username, 'password' => 'password']);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_users_with_a_temporary_password_must_change_it_first(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.edit'));
        $this->actingAs($user)->get('/settings/password')->assertOk();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
    }
}
