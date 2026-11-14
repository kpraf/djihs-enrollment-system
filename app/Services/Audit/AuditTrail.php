<?php

namespace App\Services\Audit;

use App\Enums\EnrollmentStatus;
use App\Enums\LearnerType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Strand;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns raw audit_logs rows into readable lines such as
 * "Maria Santos changed Strand from HUMSS to ABM".
 */
class AuditTrail
{
    /** @var array<string, string> */
    private const SUBJECTS = [
        Student::class => 'Learner',
        Enrollment::class => 'Enrollment',
        User::class => 'User account',
        Strand::class => 'Strand',
        Section::class => 'Section',
        SchoolYear::class => 'School year',
    ];

    /** @var array<string, string> */
    private const LABELS = [
        'lrn' => 'LRN', 'last_name' => 'Last name', 'first_name' => 'First name', 'middle_name' => 'Middle name',
        'extension_name' => 'Suffix', 'birthdate' => 'Birth date', 'sex' => 'Sex', 'religion' => 'Religion',
        'mother_tongue' => 'Mother tongue', 'is_indigenous' => 'Indigenous Peoples', 'indigenous_group' => 'IP community',
        'is_4ps_beneficiary' => '4Ps', 'is_pwd' => 'With disability', 'disability' => 'Disability',
        'house_number' => 'House no.', 'street' => 'Street', 'barangay' => 'Barangay', 'municipality' => 'Municipality',
        'province' => 'Province', 'zip_code' => 'ZIP code', 'country' => 'Country', 'contact_number' => 'Contact number',
        'father_last_name' => "Father's last name", 'father_first_name' => "Father's first name", 'father_middle_name' => "Father's middle name",
        'mother_last_name' => "Mother's last name", 'mother_first_name' => "Mother's first name", 'mother_middle_name' => "Mother's middle name",
        'guardian_last_name' => "Guardian's last name", 'guardian_first_name' => "Guardian's first name",
        'guardian_middle_name' => "Guardian's middle name", 'guardian_relationship' => 'Guardian relationship',
        'grade_level' => 'Grade', 'strand_id' => 'Strand', 'section_id' => 'Section', 'learner_type' => 'Type of learner',
        'is_irregular' => 'Irregular', 'status' => 'Status', 'weight_kg' => 'Weight (kg)', 'height_m' => 'Height (m)',
        'remarks' => 'Remarks', 'cancellation_reason' => 'Cancellation reason', 'school_year_id' => 'School year',
        'encoded_on' => 'Date encoded', 'username' => 'Username', 'role' => 'Role', 'is_active' => 'Active',
        'must_change_password' => 'Must change password', 'email' => 'Email', 'text_size' => 'Text size',
        'code' => 'Code', 'name' => 'Name', 'track' => 'Track', 'start_year' => 'Start year',
        'is_enrollment_open' => 'Enrollment open', 'grade_10_completers' => 'Grade 10 completers', 'capacity' => 'Capacity',
    ];

    /** Fields that are bookkeeping, not something a person changed. */
    private const HIDDEN = ['id', 'student_id', 'encoded_by', 'cancelled_by', 'cancelled_at', 'adviser_id', 'sort_order'];

    /**
     * History of one enrollment and its learner, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forEnrollment(Enrollment $enrollment): array
    {
        $logs = AuditLog::with('user:id,first_name,last_name')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('auditable_type', Enrollment::class)->where('auditable_id', $enrollment->id))
                ->orWhere(fn ($q) => $q->where('auditable_type', Student::class)->where('auditable_id', $enrollment->student_id)))
            ->latest('id')
            ->limit(100)
            ->get();

        return (new self)->present($logs);
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return list<array<string, mixed>>
     */
    public function present(Collection $logs): array
    {
        $strands = Strand::pluck('code', 'id');
        $sections = Section::pluck('name', 'id');
        $years = SchoolYear::all()->pluck('name', 'id');
        $subjects = $this->subjectNames($logs);

        return $logs->map(function (AuditLog $log) use ($strands, $sections, $years, $subjects) {
            $changes = [];
            $fields = array_unique([...array_keys($log->old_values ?? []), ...array_keys($log->new_values ?? [])]);

            foreach ($fields as $field) {
                if (in_array($field, self::HIDDEN, true)) {
                    continue;
                }
                $format = fn ($v) => $this->formatValue($field, $v, $strands, $sections, $years);
                $changes[] = [
                    'field' => self::LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field)),
                    'old' => $log->event === 'created' ? null : $format($log->old_values[$field] ?? null),
                    'new' => $log->event === 'deleted' ? null : $format($log->new_values[$field] ?? null),
                ];
            }

            return [
                'id' => $log->id,
                'at' => $log->created_at?->toIso8601String(),
                'user' => $log->user?->name ?? 'System',
                'event' => $log->event,
                'subject_type' => self::SUBJECTS[$log->auditable_type] ?? class_basename($log->auditable_type),
                'subject' => $subjects[$log->auditable_type][$log->auditable_id] ?? '#'.$log->auditable_id,
                'changes' => $changes,
                'ip_address' => $log->ip_address,
            ];
        })->values()->all();
    }

    /**
     * Readable names for the records the logs point at, loaded in bulk.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array<string, array<int, string>>
     */
    private function subjectNames(Collection $logs): array
    {
        $ids = $logs->groupBy('auditable_type')->map(fn ($g) => $g->pluck('auditable_id')->unique()->all());
        $names = [];

        if ($ids->has(Student::class)) {
            $names[Student::class] = Student::whereIn('id', $ids[Student::class])->get()->mapWithKeys(fn (Student $s) => [$s->id => $s->full_name])->all();
        }
        if ($ids->has(Enrollment::class)) {
            $names[Enrollment::class] = Enrollment::with('student')->whereIn('id', $ids[Enrollment::class])->get()
                ->mapWithKeys(fn (Enrollment $e) => [$e->id => $e->student->full_name])->all();
        }
        if ($ids->has(User::class)) {
            $names[User::class] = User::whereIn('id', $ids[User::class])->pluck('username', 'id')->all();
        }
        if ($ids->has(Strand::class)) {
            $names[Strand::class] = Strand::whereIn('id', $ids[Strand::class])->pluck('code', 'id')->all();
        }
        if ($ids->has(SchoolYear::class)) {
            $names[SchoolYear::class] = SchoolYear::whereIn('id', $ids[SchoolYear::class])->get()->pluck('name', 'id')->all();
        }

        return $names;
    }

    private function formatValue(string $field, mixed $value, Collection $strands, Collection $sections, Collection $years): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (true) {
            is_bool($value) || str_starts_with($field, 'is_') || $field === 'must_change_password' => $value ? 'Yes' : 'No',
            $field === 'strand_id' => $strands[$value] ?? "#{$value}",
            $field === 'section_id' => $sections[$value] ?? "#{$value}",
            $field === 'school_year_id' => $years[$value] ?? "#{$value}",
            $field === 'grade_level' => "Grade {$value}",
            $field === 'status' => EnrollmentStatus::tryFrom($value)?->label() ?? (string) $value,
            $field === 'learner_type' => LearnerType::tryFrom($value)?->label() ?? (string) $value,
            $field === 'role' => Role::tryFrom($value)?->label() ?? (string) $value,
            $field === 'sex' => ucfirst((string) $value),
            in_array($field, ['birthdate', 'encoded_on'], true) => substr((string) $value, 0, 10),
            default => (string) $value,
        };
    }
}
