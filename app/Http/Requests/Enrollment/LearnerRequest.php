<?php

namespace App\Http\Requests\Enrollment;

use App\Enums\GradeLevel;
use App\Enums\LearnerType;
use App\Enums\Sex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Fields of the enrollment form, shared by "new enrollment" and "edit".
 * Mirrors the legacy enrollment form and the school's encoding sheet.
 */
abstract class LearnerRequest extends FormRequest
{
    /** @var list<string> */
    protected const UPPERCASE = [
        'last_name', 'first_name', 'middle_name', 'extension_name',
        'house_number', 'street', 'barangay', 'municipality', 'province',
        'indigenous_group', 'disability', 'religion', 'mother_tongue',
        'father_last_name', 'father_first_name', 'father_middle_name',
        'mother_last_name', 'mother_first_name', 'mother_middle_name',
        'guardian_last_name', 'guardian_first_name', 'guardian_middle_name', 'guardian_relationship',
    ];

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (self::UPPERCASE as $field) {
            if ($this->has($field)) {
                $value = preg_replace('/\s+/u', ' ', trim((string) $this->input($field)));
                $clean[$field] = $value === '' ? null : mb_strtoupper($value);
            }
        }

        foreach (['lrn', 'contact_number', 'zip_code'] as $field) {
            if ($this->has($field)) {
                $digits = preg_replace('/[\s-]/', '', (string) $this->input($field));
                $clean[$field] = $digits === '' ? null : $digits;
            }
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'grade_level' => ['required', Rule::enum(GradeLevel::class)],
            'strand_id' => ['nullable', 'integer', Rule::exists('strands', 'id')->where('is_active', true)],
            'learner_type' => ['required', Rule::enum(LearnerType::class)],
            'is_irregular' => ['boolean'],
            'lrn' => ['required', 'digits:12'],

            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'extension_name' => ['nullable', 'string', 'max:10'],
            'birthdate' => ['required', 'date', 'before:today', 'after:1980-01-01'],
            'sex' => ['required', Rule::enum(Sex::class)],
            'religion' => ['nullable', 'string', 'max:100'],
            'mother_tongue' => ['nullable', 'string', 'max:100'],

            'is_indigenous' => ['boolean'],
            'indigenous_group' => ['nullable', 'required_if:is_indigenous,true', 'string', 'max:100'],
            'is_pwd' => ['boolean'],
            'disability' => ['nullable', 'required_if:is_pwd,true', 'string', 'max:150'],
            'is_4ps_beneficiary' => ['boolean'],
            'weight_kg' => ['nullable', 'numeric', 'between:15,200'],
            'height_m' => ['nullable', 'numeric', 'between:0.5,2.5'],

            'house_number' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:150'],
            'barangay' => ['required', 'string', 'max:100'],
            'municipality' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'zip_code' => ['nullable', 'digits:4'],
            'country' => ['nullable', 'string', 'max:60'],

            'father_last_name' => ['nullable', 'string', 'max:100'],
            'father_first_name' => ['nullable', 'string', 'max:100'],
            'father_middle_name' => ['nullable', 'string', 'max:100'],
            'mother_last_name' => ['nullable', 'string', 'max:100'],
            'mother_first_name' => ['nullable', 'string', 'max:100'],
            'mother_middle_name' => ['nullable', 'string', 'max:100'],
            'guardian_last_name' => ['nullable', 'string', 'max:100'],
            'guardian_first_name' => ['nullable', 'string', 'max:100'],
            'guardian_middle_name' => ['nullable', 'string', 'max:100'],
            'guardian_relationship' => ['nullable', 'string', 'max:50'],
            'contact_number' => ['required', 'regex:/^09\d{9}$/'],

            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lrn.digits' => 'The LRN must be exactly 12 digits.',
            'contact_number.regex' => 'Enter an 11-digit mobile number starting with 09.',
            'indigenous_group.required_if' => 'Specify the Indigenous Peoples community.',
            'disability.required_if' => 'Specify the disability.',
            'birthdate.before' => 'The birth date must be in the past.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $grade = GradeLevel::tryFrom((int) $this->input('grade_level'));

            if ($grade?->isSeniorHigh() && ! $this->filled('strand_id')) {
                $v->errors()->add('strand_id', 'Choose a strand for Grade 11 and 12.');
            }

            if ($grade && ! $grade->isSeniorHigh() && $this->filled('strand_id')) {
                $v->errors()->add('strand_id', 'Only Grade 11 and 12 learners have a strand.');
            }

            $hasParent = $this->filled('father_last_name') || $this->filled('mother_last_name') || $this->filled('guardian_last_name');
            if (! $hasParent) {
                $v->errors()->add('guardian_last_name', "Enter at least one parent's or guardian's name.");
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function studentData(): array
    {
        $data = $this->safe()->only([
            'lrn', 'last_name', 'first_name', 'middle_name', 'extension_name', 'birthdate', 'sex',
            'religion', 'mother_tongue', 'is_indigenous', 'indigenous_group', 'is_4ps_beneficiary', 'is_pwd', 'disability',
            'house_number', 'street', 'barangay', 'municipality', 'province', 'zip_code', 'country',
            'father_last_name', 'father_first_name', 'father_middle_name',
            'mother_last_name', 'mother_first_name', 'mother_middle_name',
            'guardian_last_name', 'guardian_first_name', 'guardian_middle_name', 'guardian_relationship',
            'contact_number',
        ]);

        $data['is_indigenous'] = (bool) ($data['is_indigenous'] ?? false);
        $data['is_pwd'] = (bool) ($data['is_pwd'] ?? false);
        $data['is_4ps_beneficiary'] = (bool) ($data['is_4ps_beneficiary'] ?? false);
        // Optional fields may be left out of the request entirely.
        $data['indigenous_group'] = $data['is_indigenous'] ? ($data['indigenous_group'] ?? null) : null;
        $data['disability'] = $data['is_pwd'] ? ($data['disability'] ?? null) : null;
        $data['country'] = ($data['country'] ?? null) ?: 'Philippines';

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function enrollmentData(): array
    {
        $grade = GradeLevel::from((int) $this->validated('grade_level'));

        return [
            'grade_level' => $grade,
            'strand_id' => $grade->isSeniorHigh() ? (int) $this->validated('strand_id') : null,
            'learner_type' => $this->validated('learner_type'),
            'is_irregular' => $this->boolean('is_irregular'),
            'weight_kg' => $this->validated('weight_kg'),
            'height_m' => $this->validated('height_m'),
            'remarks' => $this->validated('remarks'),
        ];
    }
}
