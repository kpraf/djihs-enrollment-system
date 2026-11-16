import { Icon } from '@/components/icon';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { RowActionLink } from '@/components/row-action';
import { StatusBadge } from '@/components/status-badge';
import { useCan } from '@/hooks/use-can';
import { useReloading } from '@/hooks/use-reloading';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type Option, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface EnrollmentRow {
    id: number;
    lrn: string | null;
    name: string;
    sex: string;
    grade_level: number;
    strand: string | null;
    section: string | null;
    learner_type: string;
    is_irregular: boolean;
    status: string;
    encoded_on: string | null;
    remarks: string | null;
}

interface Filters {
    school_year?: number | string;
    search?: string;
    grade?: string;
    strand?: string;
    status?: string;
    learner_type?: string;
    section?: string;
    documents?: string;
}

interface Props {
    enrollments: Paginated<EnrollmentRow>;
    filters: Filters;
    options: {
        schoolYears: { id: number; name: string }[];
        grades: Option<number>[];
        strands: { id: number; code: string }[];
        sections: { id: number; name: string; grade_level: number }[];
        statuses: Option[];
        learnerTypes: Option[];
    };
}

export default function EnrollmentsIndex({ enrollments, filters, options }: Props) {
    const reloading = useReloading();
    const can = useCan();
    const [search, setSearch] = useState(filters.search ?? '');
    const skipFirst = useRef(true);

    const apply = (changes: Partial<Filters>) => {
        const next = Object.fromEntries(Object.entries({ ...filters, ...changes }).filter(([, v]) => v !== '' && v !== undefined && v !== null));
        router.get('/enrollments', next, { preserveState: true, preserveScroll: true, replace: true });
    };

    // Search as the user types, after a short pause.
    useEffect(() => {
        if (skipFirst.current) {
            skipFirst.current = false;
            return;
        }
        const timer = setTimeout(() => apply({ search }), 400);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const select = (label: string, key: keyof Filters, items: { value: string | number; label: string }[], all: string) => (
        <label className="flex flex-col">
            <span className="field-label">{label}</span>
            <select className="field-input-sm h-10" value={filters[key] ?? ''} onChange={(e) => apply({ [key]: e.target.value })}>
                {all && <option value="">{all}</option>}
                {items.map((i) => (
                    <option key={i.value} value={i.value}>
                        {i.label}
                    </option>
                ))}
            </select>
        </label>
    );

    return (
        <AppLayout>
            <Head title="Learners" />
            <PageHeader
                title="Learners"
                description="Search, filter and manage every learner's record."
                actions={
                    can('encode-enrollments') && (
                        <Link href="/enrollments/create" className="btn-primary">
                            <Icon name="add" /> New Enrollment
                        </Link>
                    )
                }
            />

            <div className="panel mb-6 p-6">
                <h2 className="mb-4 text-lg font-semibold text-slate-900">Filters</h2>
                <label className="mb-4 flex flex-col">
                    <span className="field-label">Search Student</span>
                    <span className="relative">
                        <Icon name="search" className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-slate-400" />
                        <input
                            type="search"
                            className="field-input-sm h-10 bg-slate-50 pl-10"
                            placeholder="Name or LRN…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            aria-label="Search by name or LRN"
                        />
                    </span>
                </label>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {select(
                        'School Year',
                        'school_year',
                        options.schoolYears.map((y) => ({ value: y.id, label: y.name })),
                        '',
                    )}
                    {select('Grade Level', 'grade', options.grades, 'All Grades')}
                    {select(
                        'Strand',
                        'strand',
                        options.strands.map((s) => ({ value: s.id, label: s.code })),
                        'All Strands',
                    )}
                    {select('Type of Learner', 'learner_type', options.learnerTypes, 'All Types')}
                    {select('Enrollment Status', 'status', options.statuses, 'All Statuses')}
                    {select(
                        'Section',
                        'section',
                        [
                            { value: 'none', label: 'Not in a section' },
                            ...options.sections.map((s) => ({ value: s.id, label: `G${s.grade_level} ${s.name}` })),
                        ],
                        'All Sections',
                    )}
                    {select('Documents', 'documents', [{ value: 'incomplete', label: 'Incomplete only' }], 'Any')}
                </div>
                <div className="mt-4">
                    <button
                        type="button"
                        className="btn-secondary"
                        onClick={() => {
                            setSearch('');
                            router.get('/enrollments', { school_year: filters.school_year }, { replace: true });
                        }}
                    >
                        Clear All
                    </button>
                </div>
            </div>

            <div
                className={cn('panel overflow-hidden transition-opacity duration-150', reloading && 'pointer-events-none opacity-50')}
                aria-busy={reloading}
            >
                <div className="relative overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
                        <thead className="bg-slate-50 text-sm font-semibold text-slate-900">
                            <tr>
                                <th className="py-3.5 pr-3 pl-4 sm:pl-6">LRN</th>
                                <th className="px-3 py-3.5">Full Name</th>
                                <th className="px-3 py-3.5">Grade</th>
                                <th className="px-3 py-3.5">Strand</th>
                                <th className="px-3 py-3.5">Section</th>
                                <th className="px-3 py-3.5">Type</th>
                                <th className="px-3 py-3.5">Status</th>
                                <th className="py-3.5 pr-4 pl-3 text-right sm:pr-6">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200 bg-white">
                            {enrollments.data.map((e) => (
                                <tr key={e.id} className="transition-colors hover:bg-slate-50">
                                    <td className="py-4 pr-3 pl-4 font-mono text-sm whitespace-nowrap text-slate-500 sm:pl-6">
                                        {e.lrn ?? <span className="font-sans text-red-600">Missing</span>}
                                    </td>
                                    <td className="px-3 py-4 text-sm">
                                        <Link
                                            href={route('enrollments.show', e.id)}
                                            className="hover:text-primary font-medium text-slate-900 hover:underline"
                                        >
                                            {e.name}
                                        </Link>
                                        {e.remarks && <p className="mt-0.5 max-w-xs truncate text-xs text-slate-500 italic">{e.remarks}</p>}
                                    </td>
                                    <td className="px-3 py-4 whitespace-nowrap text-slate-500">{e.grade_level}</td>
                                    <td className="px-3 py-4 whitespace-nowrap text-slate-500">{e.strand ?? '—'}</td>
                                    <td className="px-3 py-4 whitespace-nowrap text-slate-500">{e.section ?? '—'}</td>
                                    <td className="px-3 py-4 whitespace-nowrap text-slate-500">
                                        {e.learner_type}
                                        {e.is_irregular && <span className="text-xs"> (Irregular)</span>}
                                    </td>
                                    <td className="px-3 py-4 whitespace-nowrap">
                                        <StatusBadge status={e.status} />
                                    </td>
                                    <td className="py-4 pr-4 pl-3 text-right whitespace-nowrap sm:pr-6">
                                        <div className="flex items-center justify-end gap-2">
                                            <RowActionLink href={route('enrollments.show', e.id)} icon="visibility" text="View" srSuffix={e.name} />
                                            {(can('edit-enrollments') || can('request-revisions')) && (
                                                <RowActionLink href={route('enrollments.edit', e.id)} icon="edit" text="Edit" srSuffix={e.name} />
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {enrollments.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="px-6 py-12 text-center text-slate-500">
                                        <Icon name="search" className="mb-2 text-4xl text-slate-300" />
                                        <p className="text-lg font-medium">No students found</p>
                                        <p className="text-sm">Try adjusting your search or filters</p>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <Pagination page={enrollments} />
            </div>
        </AppLayout>
    );
}
