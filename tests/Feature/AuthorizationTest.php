<?php

namespace Tests\Feature;

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The legacy system only hid pages in the browser. These tests prove the
 * server itself refuses every role that lacks the ability.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, Ability}>
     */
    public static function protectedPages(): array
    {
        return [
            'enrollment list' => ['/enrollments', Ability::ViewEnrollments],
            'import page' => ['/imports', Ability::ImportEnrollments],
            'user accounts' => ['/users', Ability::ManageUsers],
            'new enrollment form' => ['/enrollments/create', Ability::EncodeEnrollments],
            'audit log' => ['/audit-log', Ability::ViewAuditLog],
        ];
    }

    #[DataProvider('protectedPages')]
    public function test_each_role_gets_exactly_the_pages_it_is_allowed(string $url, Ability $ability): void
    {
        SchoolYear::factory()->active()->create();

        foreach (Role::cases() as $role) {
            $user = User::factory()->role($role)->create();
            $expected = $role->can($ability) ? 200 : 403;

            $this->actingAs($user)->get($url)->assertStatus($expected);
        }
    }

    public function test_write_endpoints_are_refused_without_the_ability(): void
    {
        $adviser = User::factory()->role(Role::Adviser)->create();
        $target = User::factory()->create();

        $this->actingAs($adviser)->post('/users', ['username' => 'x'])->assertForbidden();
        $this->actingAs($adviser)->put("/users/{$target->id}/active", ['active' => false])->assertForbidden();
        $this->actingAs($adviser)->post("/users/{$target->id}/reset-password")->assertForbidden();
    }

    public function test_guests_are_sent_to_login(): void
    {
        foreach (['/dashboard', '/enrollments', '/enrollments/create', '/imports', '/users', '/audit-log', '/students/lookup?lrn=123456789012'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }
}
