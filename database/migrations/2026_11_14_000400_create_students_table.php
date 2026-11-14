<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permanent facts about a learner. Anything that can change from one
     * school year to the next lives on the enrollments table instead.
     *
     * The legal guardian is stored here directly. The encoding sheet only
     * records one guardian, so a separate guardians table is not needed.
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('lrn', 12)->nullable()->unique();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('extension_name', 10)->nullable();
            $table->date('birthdate')->nullable();
            $table->string('sex', 6);

            $table->boolean('is_indigenous')->default(false);
            $table->string('indigenous_group')->nullable();
            $table->boolean('is_4ps_beneficiary')->default(false);

            $table->string('house_number')->nullable();
            $table->string('street')->nullable();
            $table->string('barangay')->nullable();
            $table->string('municipality')->nullable();
            $table->string('province')->nullable();
            $table->string('zip_code', 4)->nullable();
            $table->string('country')->default('Philippines');

            $table->string('guardian_last_name')->nullable();
            $table->string('guardian_first_name')->nullable();
            $table->string('guardian_middle_name')->nullable();
            $table->string('guardian_contact_number', 13)->nullable();

            $table->timestamps();

            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
