<?php

namespace Tests\Support;

use App\Models\Strand;

/**
 * A valid enrollment-form submission, with overrides.
 */
class LearnerPayload
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function make(array $overrides = []): array
    {
        return [
            'grade_level' => 11,
            'strand_id' => Strand::where('code', 'HUMSS')->value('id'),
            'learner_type' => 'new_student',
            'is_irregular' => false,
            'lrn' => '123456789012',
            'last_name' => 'dela cruz',
            'first_name' => ' juan ',
            'middle_name' => 'santos',
            'extension_name' => '',
            'birthdate' => '2009-03-15',
            'sex' => 'male',
            'religion' => 'Roman Catholic',
            'mother_tongue' => 'Tagalog',
            'is_indigenous' => false,
            'indigenous_group' => '',
            'is_pwd' => false,
            'disability' => '',
            'is_4ps_beneficiary' => true,
            'weight_kg' => '52.5',
            'height_m' => '1.62',
            'house_number' => '12',
            'street' => 'Rizal St.',
            'barangay' => 'Don Jose',
            'municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'zip_code' => '4026',
            'country' => 'Philippines',
            'father_last_name' => '',
            'father_first_name' => '',
            'father_middle_name' => '',
            'mother_last_name' => 'Reyes',
            'mother_first_name' => 'Maria',
            'mother_middle_name' => '',
            'guardian_last_name' => '',
            'guardian_first_name' => '',
            'guardian_middle_name' => '',
            'guardian_relationship' => '',
            'contact_number' => '0917 123 4567',
            'remarks' => '',
            'privacy_notice_given' => true,
            ...$overrides,
        ];
    }
}
