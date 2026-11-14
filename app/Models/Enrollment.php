<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Enums\GradeLevel;
use App\Enums\LearnerType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasLockVersion;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use Auditable, HasFactory, HasLockVersion;

    protected $fillable = [
        'student_id', 'school_year_id', 'grade_level', 'strand_id', 'section_id',
        'learner_type', 'is_irregular', 'status', 'weight_kg', 'height_m',
        'encoded_on', 'encoded_by', 'remarks',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_irregular' => false,
        'status' => 'enrolled',
        'lock_version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'grade_level' => GradeLevel::class,
            'learner_type' => LearnerType::class,
            'status' => EnrollmentStatus::class,
            'is_irregular' => 'boolean',
            'weight_kg' => 'decimal:2',
            'height_m' => 'decimal:2',
            'encoded_on' => 'date:Y-m-d',
            'cancelled_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @param  Builder<Enrollment>  $query
     */
    public function scopeEnrolled(Builder $query): void
    {
        $query->where('status', EnrollmentStatus::Enrolled);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function strand(): BelongsTo
    {
        return $this->belongsTo(Strand::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function encoder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoded_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
