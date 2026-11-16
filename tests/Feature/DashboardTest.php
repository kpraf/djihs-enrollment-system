<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $this->actingAs($user = User::factory()->create());

        $this->get('/dashboard')->assertOk();
    }

    public function test_recent_activity_folds_repeats_and_stays_short(): void
    {
        SchoolYear::factory()->active()->create();
        $this->actingAs(User::factory()->role(Role::Admin)->create());
        foreach (range(1, 6) as $i) {
            User::factory()->create();
        }
        // The newest: one account changed three times in a row.
        $ana = User::factory()->create(['first_name' => 'Ana']);
        foreach (['One', 'Two', 'Three'] as $name) {
            $ana->update(['last_name' => $name]);
        }

        $this->get('/dashboard')->assertInertia(fn ($page) => $page
            ->has('system.recent', 5)
            ->where('system.recent.0.event', 'updated')
            ->where('system.recent.0.times', 3)
            ->where('system.recent.1.event', 'created')
            ->where('system.recent.1.times', 1));
    }
}
