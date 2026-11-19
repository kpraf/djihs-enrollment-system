<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role permissions move from code into the database, so the Administrator
 * can change them on the Roles and Permissions page. Each role starts with
 * exactly what it could do before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_settings', function (Blueprint $table) {
            $table->id();
            $table->string('role', 30)->unique();
            $table->boolean('is_retired')->default(false);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 30);
            $table->string('ability', 40);
            $table->timestamps();
            $table->unique(['role', 'ability']);
        });

        $now = now();
        foreach (Role::cases() as $role) {
            DB::table('role_settings')->insert(['role' => $role->value, 'is_retired' => false, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($role->defaultAbilities() as $ability) {
                DB::table('role_permissions')->insert(['role' => $role->value, 'ability' => $ability->value, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('role_settings');
    }
};
