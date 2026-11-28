<?php

namespace Tests\Feature\Auth;

use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A form left open past the session (30 minutes) sends a security token that
 * no longer matches. People get a message and can try again, not "419 Page
 * Expired".
 */
class PageExpiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SchoolYear::factory()->active()->create();
        // Tests normally skip the token check; turn it on, as on the live site.
        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    public function test_an_old_sign_in_page_says_to_sign_in_again(): void
    {
        $user = User::factory()->create();

        $this->from('/login')->post('/login', ['username' => $user->username, 'password' => 'password', '_token' => 'expired'])
            ->assertStatus(303)->assertRedirect('/login')
            ->assertSessionHas('error', 'The sign-in page was open too long. Please sign in again.');
        $this->assertGuest();

        // Loading the page again gives a fresh token, and signing in works.
        $this->get('/login')->assertOk();
        $this->post('/login', ['username' => $user->username, 'password' => 'password', '_token' => session()->token()])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_form_after_the_session_ran_out_goes_to_sign_in(): void
    {
        $this->from('/enrollments/create')->post('/enrollments', ['_token' => 'expired'])
            ->assertStatus(303)->assertRedirect('/login')
            ->assertSessionHas('error', 'You were signed out after 30 minutes without activity. Please sign in again.');
    }

    public function test_signed_in_but_out_of_date_goes_back_without_saving(): void
    {
        $this->actingAs(User::factory()->create())->from('/settings/password')
            ->put('/settings/password', ['_token' => 'expired'])
            ->assertStatus(303)->assertRedirect('/settings/password')
            ->assertSessionHas('error', 'This page was out of date, so nothing was saved. Please try again.');
    }

    public function test_signing_out_from_an_old_page_just_shows_sign_in(): void
    {
        $this->post('/logout', ['_token' => 'expired'])->assertStatus(303)->assertRedirect('/login');
    }
}
