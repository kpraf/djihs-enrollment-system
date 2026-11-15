<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first administrator when the users table is empty.
 * Reads ADMIN_USERNAME and ADMIN_PASSWORD from the environment.
 * The admin must change the password on first login.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (User::exists()) {
            return;
        }

        $username = Str::lower(config('school.admin.username'));
        $password = config('school.admin.password');
        $generated = blank($password);

        if ($generated) {
            $password = Str::password(16, symbols: false);
        }

        User::create([
            'username' => $username,
            'first_name' => 'System',
            'last_name' => 'Administrator',
            'role' => Role::Admin,
            'password' => $password,
            'must_change_password' => true,
        ]);

        $this->command?->info("Created administrator \"{$username}\".");

        if ($generated) {
            $this->command?->warn("Generated password: {$password}");
            $this->command?->warn('Set ADMIN_PASSWORD to choose your own. You must change it after logging in.');
        }
    }
}
