<?php

namespace App\Http\Requests\Enrollment;

class StoreEnrollmentRequest extends LearnerRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'confirm_not_duplicate' => ['boolean'],
        ];
    }
}
