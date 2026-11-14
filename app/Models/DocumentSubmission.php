<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSubmission extends Model
{
    use Auditable;

    protected $fillable = ['enrollment_id', 'document_type', 'is_submitted', 'is_verified', 'notes', 'updated_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_submitted' => false, 'is_verified' => false];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'is_submitted' => 'boolean',
            'is_verified' => 'boolean',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
