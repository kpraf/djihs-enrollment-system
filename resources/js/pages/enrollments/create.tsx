import { EnrollmentForm } from '@/components/enrollment/enrollment-form';
import { type FormOptions } from '@/components/enrollment/types';
import { Icon } from '@/components/icon';
import { PageHeader } from '@/components/page-header';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';

export default function CreateEnrollment({
    schoolYear,
    options,
}: {
    schoolYear: { id: number; name: string; is_enrollment_open: boolean };
    options: FormOptions;
}) {
    const can = useCan();

    return (
        <AppLayout>
            <Head title="New Enrollment" />
            <PageHeader
                title="Student Enrollment"
                description={`Encode a learner for school year ${schoolYear.name}.`}
                actions={
                    can('import-enrollments') && (
                        <Link href="/imports" className="btn-accent">
                            <Icon name="upload_file" /> Bulk Import
                        </Link>
                    )
                }
            />
            {!schoolYear.is_enrollment_open && (
                <div className="mb-6 flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                    <Icon name="lock" /> Enrollment for school year {schoolYear.name} is closed. The ICT Coordinator can open it in School Years.
                </div>
            )}
            <EnrollmentForm
                mode="create"
                schoolYear={schoolYear.name}
                options={options}
                action={{ method: 'post', url: route('enrollments.store') }}
            />
        </AppLayout>
    );
}
