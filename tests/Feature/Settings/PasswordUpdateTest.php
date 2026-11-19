<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'new-password1',
                'password_confirmation' => 'new-password1',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/password');

        $this->assertTrue(Hash::check('new-password1', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password1',
                'password_confirmation' => 'new-password1',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/settings/password');
    }

    public function test_changing_a_temporary_password_clears_the_flag(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'new-password1',
                'password_confirmation' => 'new-password1',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertFalse($user->refresh()->must_change_password);
    }

    public function test_passwords_need_letters_and_numbers(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'onlyletters',
                'password_confirmation' => 'onlyletters',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_a_temporary_password_cannot_be_kept_as_the_new_one(): void
    {
        // The account was given the temporary password "Temp1234abcd" by an administrator.
        $user = User::factory()->mustChangePassword()->create(['password' => 'Temp1234abcd']);

        $this->actingAs($user)
            ->put('/settings/password', [
                'current_password' => 'Temp1234abcd',
                'password' => 'Temp1234abcd',
                'password_confirmation' => 'Temp1234abcd',
            ])
            ->assertSessionHasErrors(['password' => 'Choose a new password that is different from your current one.']);

        $this->assertTrue($user->refresh()->must_change_password, 'They still have to choose their own password.');
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.edit'));
    }
}
