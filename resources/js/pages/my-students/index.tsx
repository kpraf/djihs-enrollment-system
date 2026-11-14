import { Icon } from '@/components/icon';
import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';

interface Props {
    schoolYear: { id: number; name: string } | null;
    classes: { id: number; subject: string; subject_code: string | null; section: string; grade_level: number; learners_count: number }[];
    selected: { id: number; title: string; learners: { lrn: string | null; name: string; sex: string }[] } | null;
}

export default function MyStudents({ schoolYear, classes, selected }: Props) {
    return (
        <AppLayout>
            <Head title="My Students" />
            <PageHeader
                title="My Classes"
                description={schoolYear ? `Classes you teach in school year ${schoolYear.name}.` : 'There is no active school year.'}
            />

            {classes.length === 0 ? (
                <div className="panel px-6 py-12 text-center">
                    <Icon name="school" className="mb-2 text-4xl text-slate-300" />
                    <h2 className="text-lg font-semibold text-slate-900">No classes yet</h2>
                    <p className="text-sm text-slate-500">The Key Teacher assigns subject teachers to sections.</p>
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <nav className="space-y-2" aria-label="Classes">
                        {classes.map((c) => (
                            <Link
                                key={c.id}
                                href={`/my-students?class=${c.id}`}
                                preserveScroll
                                className={cn(
                                    'panel hover:border-primary block p-4 transition-colors',
                                    selected?.id === c.id && 'border-primary ring-primary ring-2',
                                )}
                            >
                                <p className="font-semibold text-slate-900">{c.subject}</p>
                                <p className="text-sm text-slate-600">{c.section}</p>
                                <p className="text-xs text-slate-500">{c.learners_count} learners</p>
                            </Link>
                        ))}
                    </nav>

                    {selected && (
                        <section className="panel overflow-hidden lg:col-span-2">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
                                <h2 className="text-lg font-semibold text-slate-900">{selected.title}</h2>
                                <div className="flex gap-2">
                                    <a className="btn-outline py-2" href={route('my-students.export', selected.id)}>
                                        <Icon name="download" /> Export to CSV
                                    </a>
                                    <button type="button" className="btn-outline py-2" onClick={() => window.print()}>
                                        <Icon name="print" /> Print
                                    </button>
                                </div>
                            </div>
                            <table className="w-full text-left text-sm">
                                <thead className="table-head">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Student Name</th>
                                        <th className="px-4 py-3 font-medium">Learner Reference Number (LRN)</th>
                                        <th className="px-4 py-3 font-medium">Sex</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {selected.learners.map((l, i) => (
                                        <tr key={i}>
                                            <td className="px-4 py-3 font-medium text-slate-900">{l.name}</td>
                                            <td className="px-4 py-3 font-mono text-slate-600">{l.lrn}</td>
                                            <td className="px-4 py-3 text-slate-600">{l.sex}</td>
                                        </tr>
                                    ))}
                                    {selected.learners.length === 0 && (
                                        <tr>
                                            <td colSpan={3} className="px-4 py-10 text-center text-slate-500">
                                                No learners in this section yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </section>
                    )}
                </div>
            )}
        </AppLayout>
    );
}
