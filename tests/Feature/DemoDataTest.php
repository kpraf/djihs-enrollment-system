<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A live site gets demo accounts only when DEMO_DATA is on, and loses the
 * ability to sign in with them once it is turned off.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    private function deploy(bool $demo): void
    {
        config(['school.demo' => $demo]);
        $this->seed(DatabaseSeeder::class);
    }

    private function activeDemoAccounts(): int
    {
        return User::where('username', 'like', 'demo.%')->where('is_active', true)->count();
    }

    public function test_a_live_site_has_no_demo_accounts_unless_asked(): void
    {
        $this->deploy(false);

        $this->assertSame(0, User::where('username', 'like', 'demo.%')->count());
        $this->assertSame(0, Enrollment::count());
        $this->assertTrue(User::where('username', 'admin')->exists());
    }

    public function test_demo_data_adds_one_account_per_role_and_fake_learners(): void
    {
        $this->deploy(true);

        $this->assertSame(6, $this->activeDemoAccounts());
        $this->assertGreaterThan(100, Enrollment::count());

        $this->post('/login', ['username' => 'demo.registrar', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_redeploying_with_demo_data_adds_nothing_twice(): void
    {
        $this->deploy(true);
        $learners = Enrollment::count();

        $this->deploy(true);

        $this->assertSame($learners, Enrollment::count());
        $this->assertSame(6, User::where('username', 'like', 'demo.%')->count());
    }

    public function test_turning_demo_data_off_switches_the_demo_accounts_off(): void
    {
        $this->deploy(true);
        $this->deploy(false);

        $this->assertSame(0, $this->activeDemoAccounts());
        $this->post('/login', ['username' => 'demo.registrar', 'password' => 'password']);
        $this->assertGuest();

        $this->deploy(true);
        $this->assertSame(6, $this->activeDemoAccounts());
    }
}
