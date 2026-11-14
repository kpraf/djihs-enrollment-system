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
     * Parents and the legal guardian are stored here directly. A learner has
     * at most one of each, so separate tables are not needed.
     *
     * lock_version increases on every save. An edit form sends back the
     * version it loaded, so a save based on stale data is refused instead of
     * silently overwriting someone else's change.
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
            $table->string('religion')->nullable();
            $table->string('mother_tongue')->nullable();

            $table->boolean('is_indigenous')->default(false);
            $table->string('indigenous_group')->nullable();
            $table->boolean('is_4ps_beneficiary')->default(false);
            $table->boolean('is_pwd')->default(false);
            $table->string('disability')->nullable();

            $table->string('house_number')->nullable();
            $table->string('street')->nullable();
            $table->string('barangay')->nullable();
            $table->string('municipality')->nullable();
            $table->string('province')->nullable();
            $table->string('zip_code', 4)->nullable();
            $table->string('country')->default('Philippines');

            $table->string('father_last_name')->nullable();
            $table->string('father_first_name')->nullable();
            $table->string('father_middle_name')->nullable();
            $table->string('mother_last_name')->nullable();
            $table->string('mother_first_name')->nullable();
            $table->string('mother_middle_name')->nullable();
            $table->string('guardian_last_name')->nullable();
            $table->string('guardian_first_name')->nullable();
            $table->string('guardian_middle_name')->nullable();
            $table->string('guardian_relationship')->nullable();
            $table->string('contact_number', 13)->nullable();

            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['last_name', 'first_name', 'birthdate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
