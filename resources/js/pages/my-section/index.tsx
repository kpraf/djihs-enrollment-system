import { CapacityBar } from '@/components/capacity-bar';
import { Icon } from '@/components/icon';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';

interface Props {
    schoolYear: { id: number; name: string } | null;
    sections: { id: number; name: string; label: string; capacity: number; filled: number }[];
    selected: {
        id: number;
        label: string;
        learners: { id: number; lrn: string | null; name: string; sex: string; contact_number: string | null; status: string }[];
    } | null;
}

export default function MySection({ schoolYear, sections, selected }: Props) {
    return (
        <AppLayout>
            <Head title="My Section" />
            <PageHeader
                title="My Sections"
                description={schoolYear ? `Sections you advise in school year ${schoolYear.name}.` : 'There is no active school year.'}
            />

            {sections.length === 0 ? (
                <div className="panel px-6 py-12 text-center">
                    <Icon name="co_present" className="mb-2 text-4xl text-slate-300" />
                    <h2 className="text-lg font-semibold text-slate-900">No Section Assigned</h2>
                    <p className="text-sm text-slate-500">Ask the Key Teacher to set you as the adviser of a section.</p>
                </div>
            ) : (
                <>
                    <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {sections.map((s) => (
                            <Link
                                key={s.id}
                                href={`/my-section?section=${s.id}`}
                                preserveScroll
                                className={cn(
                                    'panel hover:border-primary p-5 transition-colors',
                                    selected?.id === s.id && 'border-primary ring-primary ring-2',
                                )}
                            >
                                <p className="font-semibold text-slate-900">{s.label}</p>
                                <div className="mt-3">
                                    <CapacityBar filled={s.filled} capacity={s.capacity} />
                                </div>
                            </Link>
                        ))}
                    </div>

                    {selected && (
                        <section className="panel overflow-hidden">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
                                <h2 className="text-lg font-semibold text-slate-900">Students List · {selected.label}</h2>
                                <div className="flex flex-wrap gap-2">
                                    <a className="btn-outline py-2" href={route('sections.export', { section: selected.id, format: 'xlsx' })}>
                                        <Icon name="table_view" /> Export Excel
                                    </a>
                                    <a className="btn-outline py-2" href={route('sections.export', { section: selected.id, format: 'csv' })}>
                                        <Icon name="download" /> Export CSV
                                    </a>
                                    <a className="btn-outline py-2" href={route('sections.print', selected.id)} target="_blank" rel="noreferrer">
                                        <Icon name="print" /> Print / PDF
                                    </a>
                                    <a className="btn-primary py-2" href={route('sections.sf1', selected.id)}>
                                        <Icon name="description" /> Export SF1
                                    </a>
                                </div>
                            </div>
                            <div className="relative overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="table-head">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">#</th>
                                            <th className="px-4 py-3 font-medium">LRN</th>
                                            <th className="px-4 py-3 font-medium">Student Name</th>
                                            <th className="px-4 py-3 font-medium">Sex</th>
                                            <th className="px-4 py-3 font-medium">Contact Number</th>
                                            <th className="px-4 py-3 font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {selected.learners.map((l, i) => (
                                            <tr key={l.id} className="hover:bg-slate-50">
                                                <td className="px-4 py-3 text-slate-500 tabular-nums">{i + 1}</td>
                                                <td className="px-4 py-3 font-mono text-slate-600">{l.lrn}</td>
                                                <td className="px-4 py-3">
                                                    <Link
                                                        href={route('enrollments.show', l.id)}
                                                        className="hover:text-primary font-medium text-slate-900 hover:underline"
                                                    >
                                                        {l.name}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">{l.sex}</td>
                                                <td className="px-4 py-3 text-slate-600">{l.contact_number}</td>
                                                <td className="px-4 py-3">
                                                    <StatusBadge status={l.status} />
                                                </td>
                                            </tr>
                                        ))}
                                        {selected.learners.length === 0 && (
                                            <tr>
                                                <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                                                    No learners in this section yet.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}
                </>
            )}
        </AppLayout>
    );
}
