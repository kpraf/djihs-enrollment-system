<?php

namespace App\Models;

use App\Enums\Sex;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasLockVersion;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use Auditable, HasFactory, HasLockVersion;

    protected $fillable = [
        'lrn', 'last_name', 'first_name', 'middle_name', 'extension_name', 'birthdate', 'sex',
        'religion', 'mother_tongue',
        'is_indigenous', 'indigenous_group', 'is_4ps_beneficiary', 'is_pwd', 'disability',
        'house_number', 'street', 'barangay', 'municipality', 'province', 'zip_code', 'country',
        'father_last_name', 'father_first_name', 'father_middle_name',
        'mother_last_name', 'mother_first_name', 'mother_middle_name',
        'guardian_last_name', 'guardian_first_name', 'guardian_middle_name', 'guardian_relationship',
        'contact_number',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_indigenous' => false,
        'is_4ps_beneficiary' => false,
        'is_pwd' => false,
        'country' => 'Philippines',
        'lock_version' => 1,
    ];

    /** @var list<string> */
    protected $appends = ['full_name'];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date:Y-m-d',
            'sex' => Sex::class,
            'is_indigenous' => 'boolean',
            'is_4ps_beneficiary' => 'boolean',
            'is_pwd' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * "DELA CRUZ, JUAN JR. SANTOS", the order DepEd school forms use.
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(function () {
            $first = trim($this->first_name.' '.($this->extension_name ?? ''));
            $middle = $this->middle_name ? ' '.$this->middle_name : '';

            return "{$this->last_name}, {$first}{$middle}";
        });
    }

    /**
     * Age as of a given date. DepEd uses age as of the school year's opening.
     */
    public function ageOn(\DateTimeInterface $date): ?int
    {
        return $this->birthdate ? (int) $this->birthdate->diffInYears($date) : null;
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
