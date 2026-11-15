<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevisionRequest extends Model
{
    use Auditable;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'enrollment_id', 'requested_by', 'changes', 'student_version', 'enrollment_version',
        'justification', 'status', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'student_version' => 'integer',
            'enrollment_version' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
