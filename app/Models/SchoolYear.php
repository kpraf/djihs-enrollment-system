<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\SchoolYearFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class SchoolYear extends Model
{
    /** @use HasFactory<SchoolYearFactory> */
    use Auditable, HasFactory;

    protected $fillable = ['start_year', 'is_active', 'is_enrollment_open', 'is_archived', 'grade_10_completers'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => false,
        'is_enrollment_open' => false,
        'is_archived' => false,
    ];

    /** @var list<string> */
    protected $appends = ['name'];

    protected function casts(): array
    {
        return [
            'start_year' => 'integer',
            'is_active' => 'boolean',
            'is_enrollment_open' => 'boolean',
            'is_archived' => 'boolean',
            'grade_10_completers' => 'integer',
        ];
    }

    /**
     * "2024-2025" style label used on DepEd forms.
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => $this->start_year.'-'.($this->start_year + 1));
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * Finds a school year from a "2024-2025" or "2024" label.
     */
    public static function findByName(string $name): ?self
    {
        if (! preg_match('/^(\d{4})/', trim($name), $m)) {
            return null;
        }

        return static::where('start_year', (int) $m[1])->first();
    }

    /**
     * The school year that contains today. Philippine school years start mid-year.
     */
    public static function currentStartYear(): int
    {
        $today = now();

        return $today->month >= 6 ? $today->year : $today->year - 1;
    }

    /**
     * Makes this the only active school year.
     */
    public function activate(): void
    {
        DB::transaction(function () {
            // Lock every year so two people activating different years at the
            // same moment cannot leave both active.
            static::query()->lockForUpdate()->get()
                ->filter(fn (self $year) => $year->is_active && ! $year->is($this))
                ->each(fn (self $year) => $year->update(['is_active' => false]));
            $this->update(['is_active' => true]);
        });
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }
}
