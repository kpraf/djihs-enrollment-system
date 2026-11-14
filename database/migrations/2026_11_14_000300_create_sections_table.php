<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('grade_level');
            $table->foreignId('strand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->foreignId('adviser_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('capacity')->default(45);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_year_id', 'grade_level', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
