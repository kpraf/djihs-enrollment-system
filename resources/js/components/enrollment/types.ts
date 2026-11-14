export interface StrandOption {
    id: number;
    code: string;
    name: string;
    track: 'academic' | 'tvl';
}

export interface FormOptions {
    grades: { value: number; label: string }[];
    strands: StrandOption[];
    learnerTypes: { value: string; label: string; description: string }[];
}

export type LearnerForm = {
    grade_level: string;
    strand_id: string;
    learner_type: string;
    is_irregular: boolean;
    lrn: string;
    last_name: string;
    first_name: string;
    middle_name: string;
    extension_name: string;
    birthdate: string;
    sex: string;
    religion: string;
    mother_tongue: string;
    is_indigenous: boolean;
    indigenous_group: string;
    is_pwd: boolean;
    disability: string;
    is_4ps_beneficiary: boolean;
    weight_kg: string;
    height_m: string;
    house_number: string;
    street: string;
    barangay: string;
    municipality: string;
    province: string;
    zip_code: string;
    country: string;
    father_last_name: string;
    father_first_name: string;
    father_middle_name: string;
    mother_last_name: string;
    mother_first_name: string;
    mother_middle_name: string;
    guardian_last_name: string;
    guardian_first_name: string;
    guardian_middle_name: string;
    guardian_relationship: string;
    contact_number: string;
    remarks: string;
    confirm_not_duplicate: boolean;
    justification: string;
    student_version: number;
    enrollment_version: number;
};

export const emptyLearner: LearnerForm = {
    grade_level: '',
    strand_id: '',
    learner_type: '',
    is_irregular: false,
    lrn: '',
    last_name: '',
    first_name: '',
    middle_name: '',
    extension_name: '',
    birthdate: '',
    sex: '',
    religion: '',
    mother_tongue: '',
    is_indigenous: false,
    indigenous_group: '',
    is_pwd: false,
    disability: '',
    is_4ps_beneficiary: false,
    weight_kg: '',
    height_m: '',
    house_number: '',
    street: '',
    barangay: '',
    municipality: '',
    province: '',
    zip_code: '',
    country: 'Philippines',
    father_last_name: '',
    father_first_name: '',
    father_middle_name: '',
    mother_last_name: '',
    mother_first_name: '',
    mother_middle_name: '',
    guardian_last_name: '',
    guardian_first_name: '',
    guardian_middle_name: '',
    guardian_relationship: '',
    contact_number: '',
    remarks: '',
    confirm_not_duplicate: false,
    justification: '',
    student_version: 0,
    enrollment_version: 0,
};

/** Student fields the LRN lookup can fill in. */
export const studentFields = [
    'last_name',
    'first_name',
    'middle_name',
    'extension_name',
    'birthdate',
    'sex',
    'religion',
    'mother_tongue',
    'is_indigenous',
    'indigenous_group',
    'is_pwd',
    'disability',
    'is_4ps_beneficiary',
    'house_number',
    'street',
    'barangay',
    'municipality',
    'province',
    'zip_code',
    'country',
    'father_last_name',
    'father_first_name',
    'father_middle_name',
    'mother_last_name',
    'mother_first_name',
    'mother_middle_name',
    'guardian_last_name',
    'guardian_first_name',
    'guardian_middle_name',
    'guardian_relationship',
    'contact_number',
] as const;

/** Converts a saved learner record into form values. */
export function toFormValues(record: Record<string, unknown>): Partial<LearnerForm> {
    const out: Record<string, unknown> = {};
    for (const key of studentFields) {
        const v = record[key];
        out[key] = typeof v === 'boolean' ? v : v == null ? '' : String(v);
    }
    return out as Partial<LearnerForm>;
}

/** The legacy form's learner-type radios: regular and irregular groups. */
export const learnerTypeChoices = [
    { group: 'REGULAR', type: 'old_student', irregular: false, label: 'Old Student' },
    { group: 'REGULAR', type: 'new_student', irregular: false, label: 'New Student' },
    { group: 'REGULAR', type: 'als', irregular: false, label: 'ALS' },
    { group: 'REGULAR', type: 'balik_aral', irregular: false, label: 'Balik-aral' },
    { group: 'IRREGULAR', type: 'balik_aral', irregular: true, label: 'Balik-aral' },
    { group: 'IRREGULAR', type: 'repeater', irregular: true, label: 'Repeater' },
    { group: 'IRREGULAR', type: 'transferee', irregular: true, label: 'Transferee' },
] as const;
