<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New sections start with 40 seats. Sections that already exist keep the
 * number they were given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacity')->default(40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacity')->default(45)->change();
        });
    }
};
