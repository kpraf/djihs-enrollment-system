<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'username' => Str::lower(fake()->unique()->userName()),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => null,
            'role' => Role::Registrar,
            'is_active' => true,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'privacy_agreed_at' => now(),
        ];
    }

    /** A new staff member who hasn't accepted the confidentiality agreement yet. */
    public function privacyNotAgreed(): static
    {
        return $this->state(fn () => ['privacy_agreed_at' => null]);
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }
}
