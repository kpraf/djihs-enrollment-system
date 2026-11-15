<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_readable_changes_and_filters(): void
    {
        $admin = User::factory()->role(Role::Admin)->create(['first_name' => 'Maria', 'last_name' => 'Santos']);
        $this->actingAs($admin);
        $target = User::factory()->create(['username' => 'rosa']);
        $target->update(['role' => Role::Adviser]);

        $this->get('/audit-log?type=user')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('audit-log/index')
                ->where('logs.data.0.user', 'Maria Santos')
                ->where('logs.data.0.subject', 'rosa')
                ->where('logs.data.0.changes.0', ['field' => 'Role', 'old' => 'Registrar', 'new' => 'Adviser']));

        $this->get('/audit-log?type=student')->assertInertia(fn ($p) => $p->where('logs.total', 0));
        $this->get('/audit-log?type=nonsense')->assertSessionHasErrors('type');
    }
}
