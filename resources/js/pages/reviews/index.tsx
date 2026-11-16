import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field } from '@/components/form';
import { Icon } from '@/components/icon';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { RevisionCard, type Revision } from '@/components/revision-card';
import { Spinner } from '@/components/spinner';
import { useReloading } from '@/hooks/use-reloading';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type Option, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface PendingRow {
    id: number;
    lrn: string | null;
    name: string;
    sex: string;
    grade_level: number;
    strand: string | null;
    learner_type: string;
    is_irregular: boolean;
    encoded_on: string | null;
    encoder: string | null;
}

interface ApproveAllRow {
    id: number;
    lrn: string | null;
    name: string;
    grade_level: number;
    strand: string | null;
}

interface Props {
    pending: Paginated<PendingRow>;
    /** Everyone Approve All would approve; loaded when its confirmation opens. */
    approveAllList?: ApproveAllRow[];
    revisions: Revision[];
    filters: { grade?: string; strand?: string; search?: string; tab?: string };
    stats: { pending: number; approved_today: number; enrolled: number; revisions: number };
    options: { grades: Option<number>[]; strands: { id: number; code: string }[] };
    canApproveRevisions: boolean;
}

export default function Reviews({ pending, approveAllList, revisions, filters, stats, options, canApproveRevisions }: Props) {
    const reloading = useReloading();
    const [tab, setTab] = useState(filters.tab === 'revisions' && canApproveRevisions ? 'revisions' : 'enrollments');
    const [rejecting, setRejecting] = useState<PendingRow | null>(null);
    const [approving, setApproving] = useState<PendingRow | null>(null);
    const [reason, setReason] = useState('');
    const [confirmAll, setConfirmAll] = useState(false);
    const [listLoading, setListLoading] = useState(false);
    const openApproveAll = () => {
        setConfirmAll(true);
        setListLoading(true);
        router.reload({ only: ['approveAllList'], onFinish: () => setListLoading(false) });
    };
    const [busy, setBusy] = useState<number | 'all' | null>(null);

    const apply = (changes: Record<string, string>) =>
        router.get('/reviews', Object.fromEntries(Object.entries({ ...filters, ...changes }).filter(([, v]) => v)), {
            preserveState: true,
            replace: true,
        });

    const approve = (row: PendingRow) => {
        setBusy(row.id);
        router.post(route('reviews.approve', row.id), {}, { preserveScroll: true, onFinish: () => (setBusy(null), setApproving(null)) });
    };

    const reject = () => {
        if (!rejecting) return;
        setBusy(rejecting.id);
        router.post(
            route('reviews.reject', rejecting.id),
            { reason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejecting(null);
                    setReason('');
                },
                onFinish: () => setBusy(null),
            },
        );
    };

    const statCards = [
        { label: 'Pending Review', value: stats.pending, icon: 'pending' as const, tone: 'bg-orange-50 text-orange-500', text: 'text-orange-500' },
        {
            label: 'Approved Today',
            value: stats.approved_today,
            icon: 'check_circle' as const,
            tone: 'bg-green-50 text-green-500',
            text: 'text-green-500',
        },
        { label: 'Total Enrolled', value: stats.enrolled, icon: 'school' as const, tone: 'bg-green-100 text-primary', text: 'text-primary' },
    ];

    return (
        <AppLayout>
            <Head title="Review Enrollments" />
            <PageHeader title="Review Enrollments" description="Review and approve pending student enrollments." />

            <div className="mb-8 grid grid-cols-1 gap-6 sm:grid-cols-3">
                {statCards.map((s) => (
                    <div key={s.label} className="panel flex items-center justify-between p-6">
                        <div>
                            <p className="text-sm text-slate-600">{s.label}</p>
                            <p className={cn('text-3xl font-bold tabular-nums', s.text)}>{s.value}</p>
                        </div>
                        <div className={cn('flex size-12 items-center justify-center rounded-lg', s.tone)}>
                            <Icon name={s.icon} />
                        </div>
                    </div>
                ))}
            </div>

            {canApproveRevisions && (
                <div className="mb-6 flex gap-2" role="tablist">
                    {[
                        ['enrollments', `New Enrollments (${stats.pending})`],
                        ['revisions', `Revision Requests (${stats.revisions})`],
                    ].map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={tab === key}
                            onClick={() => setTab(key)}
                            className={cn(
                                'rounded-lg px-4 py-2.5 text-sm font-semibold',
                                tab === key ? 'bg-primary text-white' : 'bg-white text-slate-600 hover:bg-slate-100',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            )}

            {tab === 'enrollments' && (
                <>
                    <div className="panel mb-6 flex flex-wrap items-center gap-3 p-4">
                        <select
                            className="field-input-sm h-10 w-auto"
                            value={filters.grade ?? ''}
                            onChange={(e) => apply({ grade: e.target.value })}
                            aria-label="Grade level"
                        >
                            <option value="">All Grade Levels</option>
                            {options.grades.map((g) => (
                                <option key={g.value} value={g.value}>
                                    {g.label}
                                </option>
                            ))}
                        </select>
                        <select
                            className="field-input-sm h-10 w-auto"
                            value={filters.strand ?? ''}
                            onChange={(e) => apply({ strand: e.target.value })}
                            aria-label="Strand"
                        >
                            <option value="">All Strands</option>
                            {options.strands.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.code}
                                </option>
                            ))}
                        </select>
                        <button type="button" className="btn-primary" onClick={() => router.reload()}>
                            <Icon name="refresh" /> Refresh
                        </button>
                        <button type="button" className="btn-accent ml-auto" disabled={pending.total === 0} onClick={openApproveAll}>
                            <Icon name="task_alt" /> Approve All
                        </button>
                    </div>

                    <div
                        className={cn('panel overflow-hidden transition-opacity duration-150', reloading && 'pointer-events-none opacity-50')}
                        aria-busy={reloading}
                    >
                        <div className="relative overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="table-head">
                                    <tr>
                                        <th className="px-6 py-3 font-medium">Student</th>
                                        <th className="px-6 py-3 font-medium">Grade</th>
                                        <th className="px-6 py-3 font-medium">Type</th>
                                        <th className="px-6 py-3 font-medium">Encoded</th>
                                        <th className="px-6 py-3 text-right font-medium">Action</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {pending.data.map((r) => (
                                        <tr key={r.id} className="hover:bg-slate-50">
                                            <td className="px-6 py-4">
                                                <Link
                                                    href={route('enrollments.show', r.id)}
                                                    className="hover:text-primary font-medium text-slate-900 hover:underline"
                                                >
                                                    {r.name}
                                                </Link>
                                                <p className="font-mono text-xs text-slate-500">{r.lrn}</p>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-slate-600">
                                                {r.grade_level}
                                                {r.strand ? ` · ${r.strand}` : ''}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-slate-600">
                                                {r.learner_type}
                                                {r.is_irregular && ' (Irregular)'}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-slate-600">
                                                {r.encoded_on}
                                                {r.encoder && <span className="block text-xs text-slate-400">by {r.encoder}</span>}
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        className="btn-primary px-3 py-1.5"
                                                        disabled={busy !== null}
                                                        onClick={() => setApproving(r)}
                                                    >
                                                        Approve <span className="sr-only">{r.name}</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn-danger px-3 py-1.5"
                                                        disabled={busy !== null}
                                                        onClick={() => setRejecting(r)}
                                                    >
                                                        Reject <span className="sr-only">{r.name}</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {pending.data.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                                <Icon name="task_alt" className="mb-2 text-4xl text-green-300" />
                                                <p className="text-lg font-medium">All caught up</p>
                                                <p className="text-sm">There are no enrollments waiting for review.</p>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <Pagination page={pending} />
                    </div>
                </>
            )}

            {tab === 'revisions' && (
                <div className="space-y-4">
                    {revisions.length === 0 && <div className="panel px-6 py-12 text-center text-slate-500">No revision requests are waiting.</div>}
                    {revisions.map((r) => (
                        <RevisionCard key={r.id} revision={r} canDecide showLearner />
                    ))}
                </div>
            )}

            <ConfirmDialog
                open={approving !== null}
                onClose={() => setApproving(null)}
                onConfirm={() => approving && approve(approving)}
                processing={busy !== null}
                title={`Approve ${approving?.name ?? ''}?`}
                message={
                    approving && (
                        <>
                            Grade {approving.grade_level}
                            {approving.strand ? ` · ${approving.strand}` : ''} · {approving.learner_type}
                            {approving.lrn ? ` · LRN ${approving.lrn}` : ''}
                            <span className="mt-2 block">The learner will be counted as enrolled and can be placed in a section.</span>
                        </>
                    )
                }
                confirmLabel="Yes, approve"
                icon="check_circle"
                tone="primary"
            />
            <ConfirmDialog
                open={rejecting !== null}
                onClose={() => setRejecting(null)}
                onConfirm={reject}
                processing={busy !== null}
                title={`Reject ${rejecting?.name ?? ''}?`}
                message="The encoder can correct the details and submit again."
                confirmLabel="Reject"
                icon="block"
            >
                <div className="mt-6 text-left">
                    <Field label="Reason" required>
                        {(p) => (
                            <input
                                {...p}
                                className="field-input"
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                placeholder="e.g., LRN does not match the report card"
                            />
                        )}
                    </Field>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={confirmAll}
                onClose={() => setConfirmAll(false)}
                onConfirm={() => {
                    setBusy('all');
                    router.post(route('reviews.approve-all'), filters, {
                        preserveScroll: true,
                        onFinish: () => (setBusy(null), setConfirmAll(false)),
                    });
                }}
                processing={busy !== null || listLoading}
                busyText={listLoading ? 'Loading the list…' : 'Approving…'}
                title={`Approve all ${pending.total} pending enrollments?`}
                message="These learners will be counted as enrolled. Any already reviewed by someone else in the meantime are skipped."
                confirmLabel={`Approve ${pending.total}`}
                icon="task_alt"
                tone="primary"
            >
                <ApproveAllList rows={listLoading ? undefined : approveAllList} total={pending.total} />
            </ConfirmDialog>
        </AppLayout>
    );
}

/** The learners Approve All will approve, so they can be checked first. */
function ApproveAllList({ rows, total }: { rows: ApproveAllRow[] | undefined; total: number }) {
    if (!rows) {
        return (
            <p className="mt-5 flex items-center justify-center gap-2 text-sm text-slate-500" role="status">
                <Spinner className="text-primary" /> Loading the list…
            </p>
        );
    }

    return (
        <div className="mt-5 text-left">
            <div className="max-h-64 overflow-y-auto rounded-lg border border-slate-200">
                <table className="w-full text-sm">
                    <thead className="table-head sticky top-0">
                        <tr>
                            <th className="px-3 py-2 text-left font-medium">#</th>
                            <th className="px-3 py-2 text-left font-medium">Learner</th>
                            <th className="px-3 py-2 text-left font-medium">Grade</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((r, i) => (
                            <tr key={r.id}>
                                <td className="px-3 py-2 text-slate-400 tabular-nums">{i + 1}</td>
                                <td className="px-3 py-2">
                                    <span className="block font-medium text-slate-900">{r.name}</span>
                                    <span className="text-xs text-slate-500">{r.lrn ? `LRN ${r.lrn}` : 'No LRN'}</span>
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap text-slate-600">
                                    {r.grade_level}
                                    {r.strand ? ` · ${r.strand}` : ''}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {total > rows.length && (
                <p className="mt-2 text-xs text-slate-500">
                    Showing the first {rows.length} of {total}. Use the grade or strand filter to approve in smaller groups.
                </p>
            )}
        </div>
    );
}
