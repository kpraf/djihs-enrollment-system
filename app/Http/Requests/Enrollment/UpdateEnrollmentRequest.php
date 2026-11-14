<?php

namespace App\Http\Requests\Enrollment;

class UpdateEnrollmentRequest extends LearnerRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'student_version' => ['required', 'integer'],
            'enrollment_version' => ['required', 'integer'],
        ];
    }
}
