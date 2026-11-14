import { EnrollmentForm } from '@/components/enrollment/enrollment-form';
import { toFormValues, type FormOptions, type LearnerForm } from '@/components/enrollment/types';
import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { useMemo } from 'react';
import { type EnrollmentRecord } from './show';

export default function EditEnrollment({ enrollment, options }: { enrollment: EnrollmentRecord; options: FormOptions }) {
    const initial = useMemo<Partial<LearnerForm>>(
        () => ({
            ...toFormValues(enrollment.student),
            lrn: enrollment.student.lrn ?? '',
            grade_level: String(enrollment.grade_level),
            strand_id: enrollment.strand_id ? String(enrollment.strand_id) : '',
            learner_type: enrollment.learner_type,
            is_irregular: enrollment.is_irregular,
            weight_kg: enrollment.weight_kg ?? '',
            height_m: enrollment.height_m ?? '',
            remarks: enrollment.remarks ?? '',
            student_version: enrollment.student_version,
            enrollment_version: enrollment.enrollment_version,
        }),
        [enrollment],
    );

    return (
        <AppLayout>
            <Head title={`Edit ${enrollment.student.full_name}`} />
            <PageHeader title="Edit Enrollment" description={enrollment.student.full_name} />
            <EnrollmentForm
                key={`${enrollment.student_version}-${enrollment.enrollment_version}`}
                mode="edit"
                schoolYear={enrollment.school_year}
                options={options}
                initial={initial}
                action={{ method: 'put', url: route('enrollments.update', enrollment.id) }}
                cancelHref={route('enrollments.show', enrollment.id)}
            />
        </AppLayout>
    );
}
