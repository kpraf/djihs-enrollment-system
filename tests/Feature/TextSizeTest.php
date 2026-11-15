<?php

namespace Tests\Feature;

use App\Enums\TextSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TextSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_start_at_normal_size(): void
    {
        $this->get('/login')->assertSee('style="font-size: 100%"', false);
    }

    public function test_a_signed_in_users_choice_is_saved_to_their_account_and_applied_on_load(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/dashboard')->put('/settings/text-size', ['text_size' => 'xlarge'])->assertRedirect('/dashboard');

        $this->assertSame(TextSize::ExtraLarge, $user->fresh()->text_size);
        $this->actingAs($user->fresh())->get('/dashboard')
            ->assertSee('style="font-size: 130%"', false)
            ->assertInertia(fn ($p) => $p->where('textSize.value', 'xlarge')->where('textSize.percent', 130));
    }

    public function test_guests_can_enlarge_the_login_page(): void
    {
        $this->from('/login')->put('/settings/text-size', ['text_size' => 'large'])->assertCookie(TextSize::COOKIE, 'large');

        $this->withCookie(TextSize::COOKIE, 'large')->get('/login')->assertSee('style="font-size: 115%"', false);
    }

    public function test_the_account_setting_wins_over_the_cookie(): void
    {
        $user = User::factory()->create(['text_size' => 'normal']);

        $this->withCookie(TextSize::COOKIE, 'xlarge')->actingAs($user)->get('/dashboard')->assertSee('style="font-size: 100%"', false);
    }

    public function test_unknown_sizes_are_rejected(): void
    {
        $this->put('/settings/text-size', ['text_size' => 'huge'])->assertSessionHasErrors('text_size');
        $this->withCookie(TextSize::COOKIE, '"><script>')->get('/login')->assertSee('style="font-size: 100%"', false);
    }

    public function test_it_works_while_a_password_change_is_pending(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->from('/settings/password')->put('/settings/text-size', ['text_size' => 'large'])->assertRedirect('/settings/password');
        $this->assertSame(TextSize::Large, $user->fresh()->text_size);
    }

    public function test_a_size_picked_on_the_login_page_carries_on_after_signing_in(): void
    {
        $user = User::factory()->create(['text_size' => null]);

        $this->withCookie(TextSize::COOKIE, 'xlarge')->actingAs($user)->get('/dashboard')->assertSee('style="font-size: 130%"', false);
    }
}
