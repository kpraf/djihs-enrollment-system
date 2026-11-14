<?php

namespace App\Models;

use App\Enums\GradeLevel;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    use Auditable;

    protected $fillable = ['school_year_id', 'grade_level', 'strand_id', 'name', 'adviser_id', 'capacity'];

    protected function casts(): array
    {
        return [
            'grade_level' => GradeLevel::class,
            'capacity' => 'integer',
        ];
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function strand(): BelongsTo
    {
        return $this->belongsTo(Strand::class);
    }

    public function adviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adviser_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
