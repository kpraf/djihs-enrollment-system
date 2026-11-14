import { EnrollmentForm } from '@/components/enrollment/enrollment-form';
import { type FormOptions } from '@/components/enrollment/types';
import { Icon } from '@/components/icon';
import { PageHeader } from '@/components/page-header';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';

export default function CreateEnrollment({ schoolYear, options }: { schoolYear: { id: number; name: string }; options: FormOptions }) {
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
            <EnrollmentForm
                mode="create"
                schoolYear={schoolYear.name}
                options={options}
                action={{ method: 'post', url: route('enrollments.store') }}
            />
        </AppLayout>
    );
}
