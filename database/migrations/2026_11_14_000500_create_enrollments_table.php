<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per learner per school year, mirroring one row of the
     * school's ENROLLMENT_ENCODING sheet.
     *
     * section_id replaces the legacy sectionassignment table. Weight and
     * height are per year because they feed SF8 health reporting.
     * encoded_on is nullable only because some imported rows lack a date.
     */
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('grade_level');
            $table->foreignId('strand_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('learner_type');
            $table->boolean('is_irregular')->default(false);
            $table->string('status')->default('enrolled');

            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_m', 3, 2)->nullable();

            $table->date('encoded_on')->nullable();
            $table->foreignId('encoded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();

            $table->timestamps();

            $table->unique(['student_id', 'school_year_id']);
            $table->index(['school_year_id', 'grade_level', 'strand_id', 'status']);
            $table->index(['school_year_id', 'encoded_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
