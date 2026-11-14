import { NativeSelect } from '@/components/native-select';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Option, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface EnrollmentRow {
    id: number;
    lrn: string | null;
    name: string;
    sex: string;
    grade_level: number;
    strand: string | null;
    learner_type: string;
    is_irregular: boolean;
    status: 'enrolled' | 'cancelled';
    encoded_on: string | null;
    remarks: string | null;
}

interface Filters {
    school_year?: number;
    search?: string;
    grade?: string;
    strand?: string;
    status?: string;
    learner_type?: string;
}

interface Props {
    enrollments: Paginated<EnrollmentRow>;
    filters: Filters;
    options: {
        schoolYears: { id: number; name: string }[];
        grades: Option<number>[];
        strands: { id: number; code: string }[];
        statuses: Option[];
        learnerTypes: Option[];
    };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Enrollments', href: '/enrollments' }];

export default function EnrollmentsIndex({ enrollments, filters, options }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const firstRender = useRef(true);

    const apply = (changes: Partial<Filters>) => {
        const next = Object.fromEntries(Object.entries({ ...filters, ...changes }).filter(([, v]) => v !== '' && v !== undefined && v !== null));
        router.get('/enrollments', next, { preserveState: true, replace: true });
    };

    // Search as the user types, but wait until they pause.
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const timer = setTimeout(() => apply({ search }), 350);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Enrollments" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Enrollments</h1>
                    <p className="text-muted-foreground text-sm">{enrollments.total} learners match the current filters.</p>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                    <div className="relative sm:col-span-2">
                        <Search className="text-muted-foreground pointer-events-none absolute top-2.5 left-3 size-4" />
                        <Input className="pl-9" placeholder="Search name or LRN" value={search} onChange={(e) => setSearch(e.target.value)} />
                    </div>
                    <NativeSelect
                        value={filters.school_year ?? ''}
                        onChange={(e) => apply({ school_year: Number(e.target.value) })}
                        aria-label="School year"
                    >
                        {options.schoolYears.map((y) => (
                            <option key={y.id} value={y.id}>
                                SY {y.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect value={filters.grade ?? ''} onChange={(e) => apply({ grade: e.target.value })} aria-label="Grade level">
                        <option value="">All grades</option>
                        {options.grades.map((g) => (
                            <option key={g.value} value={g.value}>
                                {g.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect value={filters.strand ?? ''} onChange={(e) => apply({ strand: e.target.value })} aria-label="Strand">
                        <option value="">All strands</option>
                        {options.strands.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.code}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect value={filters.status ?? ''} onChange={(e) => apply({ status: e.target.value })} aria-label="Status">
                        <option value="">Any status</option>
                        {options.statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                <Card className="gap-0 overflow-hidden py-0">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr className="text-left">
                                    <th className="px-3 py-2 font-medium">Learner</th>
                                    <th className="px-3 py-2 font-medium">LRN</th>
                                    <th className="px-3 py-2 font-medium">Grade</th>
                                    <th className="px-3 py-2 font-medium">Strand</th>
                                    <th className="px-3 py-2 font-medium">Type</th>
                                    <th className="px-3 py-2 font-medium">Status</th>
                                    <th className="px-3 py-2 font-medium">Encoded</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {enrollments.data.map((e) => (
                                    <tr key={e.id} className="align-top">
                                        <td className="px-3 py-2">
                                            <p className="font-medium">{e.name}</p>
                                            <p className="text-muted-foreground text-xs">{e.sex}</p>
                                            {e.remarks && <p className="text-muted-foreground mt-1 text-xs italic">{e.remarks}</p>}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">
                                            {e.lrn ?? <span className="text-destructive font-sans">Missing</span>}
                                        </td>
                                        <td className="px-3 py-2">{e.grade_level}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">{e.strand ?? '—'}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            {e.learner_type}
                                            {e.is_irregular && <span className="text-muted-foreground"> · Irregular</span>}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Badge
                                                variant={e.status === 'enrolled' ? 'default' : 'secondary'}
                                                className={e.status === 'enrolled' ? 'bg-green-700' : ''}
                                            >
                                                {e.status === 'enrolled' ? 'Enrolled' : 'Cancelled'}
                                            </Badge>
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap tabular-nums">{e.encoded_on ?? '—'}</td>
                                    </tr>
                                ))}
                                {enrollments.data.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-muted-foreground px-3 py-10 text-center">
                                            No learners match these filters.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Pagination page={enrollments} />
            </div>
        </AppLayout>
    );
}
