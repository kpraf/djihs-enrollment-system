<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data Privacy Act (RA 10173) records: when the privacy notice was given
 * for an enrollment and by whom, and when each staff member accepted the
 * confidentiality agreement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->timestamp('privacy_notice_at')->nullable();
            $table->foreignId('privacy_notice_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('privacy_agreed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('privacy_notice_by');
            $table->dropColumn('privacy_notice_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('privacy_agreed_at');
        });
    }
};
