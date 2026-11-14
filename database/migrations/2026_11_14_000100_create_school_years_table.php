<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('start_year')->unique();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_enrollment_open')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->unsignedSmallInteger('grade_10_completers')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_years');
    }
};
