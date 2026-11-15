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
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use Auditable, HasFactory, HasLockVersion;

    protected $fillable = [
        'student_id', 'school_year_id', 'grade_level', 'strand_id', 'section_id',
        'learner_type', 'is_irregular', 'status', 'weight_kg', 'height_m',
        'encoded_on', 'encoded_by', 'remarks',
        'reviewed_at', 'reviewed_by', 'status_changed_at', 'status_changed_by', 'status_reason',
        'privacy_notice_at', 'privacy_notice_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_irregular' => false,
        'status' => 'pending',
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
            'reviewed_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'privacy_notice_at' => 'datetime',
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

    /**
     * Every word must match the LRN or one of the names, so
     * "juan dela cruz" and "dela cruz, juan" both find the same learner.
     *
     * @param  Builder<Enrollment>  $query
     */
    public function scopeSearch(Builder $query, ?string $text): void
    {
        foreach (preg_split('/[\s,]+/', mb_strtoupper(trim((string) $text)), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $term = '%'.addcslashes($word, '%_\\').'%';
            $query->whereHas('student', fn (Builder $s) => $s->where(fn (Builder $w) => $w
                ->where('lrn', 'like', $term)
                ->orWhereRaw('upper(last_name) like ?', [$term])
                ->orWhereRaw('upper(first_name) like ?', [$term])
                ->orWhereRaw('upper(middle_name) like ?', [$term])));
        }
    }

    /**
     * Documents still missing, by the legacy rule (see DocumentType::isComplete).
     *
     * @param  Builder<Enrollment>  $query
     */
    public function scopeDocumentsIncomplete(Builder $query): void
    {
        $submitted = fn (array $types) => fn (Builder $d) => $d->whereIn('document_type', $types)->where('is_submitted', true);

        $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('documents', $submitted(['psa_birth_cert', 'local_birth_cert']))
            ->orWhereDoesntHave('documents', $submitted(['report_card']))
            ->orWhere(fn (Builder $t) => $t->where('learner_type', 'transferee')->whereDoesntHave('documents', $submitted(['form_137']))));
    }

    public function privacyNoticeGiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'privacy_notice_by');
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DocumentSubmission::class);
    }

    public function revisionRequests(): HasMany
    {
        return $this->hasMany(RevisionRequest::class);
    }
}
