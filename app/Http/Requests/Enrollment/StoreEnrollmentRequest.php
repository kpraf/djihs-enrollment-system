<?php

namespace App\Http\Requests\Enrollment;

class StoreEnrollmentRequest extends LearnerRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'confirm_not_duplicate' => ['boolean'],
            'privacy_notice_given' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'privacy_notice_given.accepted' => 'Confirm that the parent, guardian or adult learner was given the privacy notice.',
        ];
    }

    /**
     * Records that the privacy notice was given, as the Data Privacy Act
     * requires before personal data is collected.
     */
    public function enrollmentData(): array
    {
        return [
            ...parent::enrollmentData(),
            'privacy_notice_at' => now(),
            'privacy_notice_by' => $this->user()->id,
        ];
    }
}
