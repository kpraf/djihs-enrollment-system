import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field, TextInput } from '@/components/form';
import { Icon } from '@/components/icon';
import { Modal } from '@/components/modal';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Year {
    id: number;
    name: string;
    start_year: number;
    is_active: boolean;
    is_enrollment_open: boolean;
    is_archived: boolean;
    grade_10_completers: number | null;
    enrollments_count: number;
    enrolled_count: number;
    sections_count: number;
}

export default function SchoolYears({ years, suggestedStart }: { years: Year[]; suggestedStart: number }) {
    const [creating, setCreating] = useState(false);
    const [showArchived, setShowArchived] = useState(false);
    const [activating, setActivating] = useState<Year | null>(null);
    const active = years.find((y) => y.is_active);
    const shown = years.filter((y) => y.is_archived === showArchived);
    const opts = { preserveScroll: true };

    return (
        <AppLayout>
            <Head title="School Years" />
            <PageHeader
                title="School Years"
                description="Create school years, choose the active one, and open or close enrollment."
                actions={
                    <>
                        <button type="button" className="btn-outline" onClick={() => setShowArchived((v) => !v)}>
                            <Icon name="archive" /> {showArchived ? 'Current Years' : 'Archived Years'}
                        </button>
                        <button type="button" className="btn-primary" onClick={() => setCreating(true)}>
                            <Icon name="add" /> New School Year
                        </button>
                    </>
                }
            />

            {active && (
                <section className="mb-8 rounded-xl border border-green-200 bg-green-50 p-6">
                    <p className="text-primary text-sm font-medium tracking-wide uppercase">Currently Active</p>
                    <div className="mt-1 flex flex-wrap items-center gap-4">
                        <h2 className="text-3xl font-black text-slate-900">SY {active.name}</h2>
                        <span
                            className={cn(
                                'rounded-full px-3 py-1 text-sm font-semibold',
                                active.is_enrollment_open ? 'bg-primary text-white' : 'bg-slate-200 text-slate-700',
                            )}
                        >
                            Enrollment {active.is_enrollment_open ? 'open' : 'closed'}
                        </span>
                        <button
                            type="button"
                            className="btn-outline ml-auto"
                            onClick={() =>
                                router.put(route('school-years.update', active.id), { is_enrollment_open: !active.is_enrollment_open }, opts)
                            }
                        >
                            <Icon name={active.is_enrollment_open ? 'lock' : 'how_to_reg'} />{' '}
                            {active.is_enrollment_open ? 'Close Enrollment' : 'Open Enrollment'}
                        </button>
                    </div>
                    <p className="mt-2 text-sm text-slate-600">
                        {active.enrolled_count} enrolled · {active.enrollments_count - active.enrolled_count} other · {active.sections_count} sections
                    </p>
                </section>
            )}

            <h2 className="mb-4 text-xl font-bold text-slate-900">{showArchived ? 'Archived School Years' : 'All School Years'}</h2>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                {shown.map((y) => (
                    <YearCard key={y.id} year={y} onActivate={() => setActivating(y)} />
                ))}
                {shown.length === 0 && (
                    <p className="text-sm text-slate-500">{showArchived ? 'No archived school years.' : 'No school years yet.'}</p>
                )}
            </div>

            <CreateYear open={creating} suggestedStart={suggestedStart} onClose={() => setCreating(false)} />
            <ConfirmDialog
                open={activating !== null}
                onClose={() => setActivating(null)}
                onConfirm={() =>
                    activating && router.post(route('school-years.activate', activating.id), {}, { ...opts, onFinish: () => setActivating(null) })
                }
                title={`Make ${activating?.name ?? ''} the active school year?`}
                message="New enrollments, sections and the dashboard will use this school year."
                confirmLabel="Make Active"
                icon="calendar_month"
                tone="primary"
            />
        </AppLayout>
    );
}

function YearCard({ year: y, onActivate }: { year: Year; onActivate: () => void }) {
    const [completers, setCompleters] = useState(y.grade_10_completers?.toString() ?? '');
    const opts = { preserveScroll: true };

    return (
        <div className={cn('panel p-5', y.is_active && 'ring-primary ring-2')}>
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h3 className="text-xl font-bold text-slate-900">SY {y.name}</h3>
                    <p className="text-sm text-slate-500">
                        {y.enrolled_count} enrolled · {y.sections_count} sections
                    </p>
                </div>
                {y.is_active ? <StatusBadge status="active" /> : y.is_archived ? <StatusBadge status="archived" /> : null}
            </div>
            <form
                className="mt-4 flex items-end gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.put(route('school-years.update', y.id), { grade_10_completers: completers === '' ? null : Number(completers) }, opts);
                }}
            >
                <label className="flex flex-1 flex-col text-xs text-slate-600">
                    Grade 10 completers (for retention)
                    <input type="number" min={0} className="field-input-sm mt-1" value={completers} onChange={(e) => setCompleters(e.target.value)} />
                </label>
                <button type="submit" className="btn-outline py-2">
                    Save
                </button>
            </form>
            <div className="mt-4 flex flex-wrap gap-2">
                {!y.is_active && !y.is_archived && (
                    <button type="button" className="btn-primary py-2" onClick={onActivate}>
                        Make Active
                    </button>
                )}
                {!y.is_active && (
                    <button
                        type="button"
                        className="btn-outline py-2"
                        onClick={() => router.put(route('school-years.archive', y.id), { archived: !y.is_archived }, opts)}
                    >
                        <Icon name={y.is_archived ? 'unarchive' : 'archive'} /> {y.is_archived ? 'Unarchive' : 'Archive'}
                    </button>
                )}
            </div>
        </div>
    );
}

function CreateYear({ open, suggestedStart, onClose }: { open: boolean; suggestedStart: number; onClose: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({ start_year: String(suggestedStart), grade_10_completers: '' });
    const start = Number(data.start_year);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('school-years.store'), { preserveScroll: true, onSuccess: () => (reset(), onClose()) });
    };

    return (
        <Modal open={open} onClose={onClose} title="Create School Year" size="sm">
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field
                    label="Starting year"
                    required
                    error={errors.start_year}
                    hint={start ? `Creates school year ${start}-${start + 1}.` : undefined}
                >
                    {(p) => (
                        <TextInput
                            {...p}
                            type="number"
                            min={2000}
                            max={2100}
                            value={data.start_year}
                            onChange={(e) => setData('start_year', e.target.value)}
                            data-autofocus
                        />
                    )}
                </Field>
                <Field label="Grade 10 completers (optional)" error={errors.grade_10_completers}>
                    {(p) => (
                        <TextInput
                            {...p}
                            type="number"
                            min={0}
                            value={data.grade_10_completers}
                            onChange={(e) => setData('grade_10_completers', e.target.value)}
                        />
                    )}
                </Field>
                <div className="flex gap-3 pt-2">
                    <button type="button" className="btn-outline flex-1 py-3" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" className="btn-primary flex-1 py-3" disabled={processing}>
                        Create
                    </button>
                </div>
            </form>
        </Modal>
    );
}
