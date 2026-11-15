<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings/profile')->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', [
                'first_name' => 'Maria Clara',
                'last_name' => 'Santos',
                'email' => 'maria@example.com',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();
        $this->assertSame('Maria Clara Santos', $user->name);
        $this->assertSame('maria@example.com', $user->email);
    }

    public function test_email_is_optional(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->patch('/settings/profile', ['first_name' => 'A', 'last_name' => 'B', 'email' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->refresh()->email);
    }

    public function test_users_can_not_delete_their_own_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertMethodNotAllowed();
        $this->assertNotNull($user->fresh());
    }
}
