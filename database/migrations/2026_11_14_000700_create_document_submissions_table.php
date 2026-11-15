<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The enrollment document checklist. One row per document actually
     * ticked, so two staff updating different documents never collide.
     */
    public function up(): void
    {
        Schema::create('document_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 30);
            $table->boolean('is_submitted')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->string('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['enrollment_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_submissions');
    }
};
