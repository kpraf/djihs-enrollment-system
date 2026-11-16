import { Icon } from '@/components/icon';
import { Modal } from '@/components/modal';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatCard } from '@/components/stat-card';
import { useCan } from '@/hooks/use-can';
import { useReloading } from '@/hooks/use-reloading';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type HistoryEntry } from '@/pages/enrollments/show';
import { type Option, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    logs: Paginated<HistoryEntry & { ip_address: string | null }>;
    filters: { type?: string; activity?: string; user?: string; from?: string; to?: string };
    stats: { total: number; today: number; week: number; month: number };
    users: { id: number; name: string }[];
    types: Option[];
}

const when = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
const verbs = { created: 'Created', updated: 'Updated', deleted: 'Deleted', viewed: 'Viewed', exported: 'Downloaded' };
const tones = {
    created: 'bg-green-100 text-green-800',
    updated: 'bg-blue-100 text-blue-800',
    deleted: 'bg-red-100 text-red-800',
    viewed: 'bg-slate-100 text-slate-700',
    exported: 'bg-amber-100 text-amber-800',
};

export default function AuditLog({ logs, filters, stats, users, types }: Props) {
    const reloading = useReloading();
    const [draft, setDraft] = useState(filters);
    const [backupOpen, setBackupOpen] = useState(false);
    const can = useCan();

    const apply = () => {
        const clean = Object.fromEntries(Object.entries(draft).filter(([, v]) => v));
        router.get('/audit-log', clean, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Audit Log" />
            <PageHeader
                title="System Audit Log"
                description="Every change, and every time someone viewed or downloaded personal data."
                actions={
                    <>
                        <a
                            href={route('audit-log.export', { from: filters.from, to: filters.to })}
                            className="btn bg-blue-600 text-white hover:bg-blue-700"
                        >
                            <Icon name="download" /> Export Logs
                        </a>
                        {can('backup-data') && (
                            <button type="button" className="btn-outline" onClick={() => setBackupOpen(true)}>
                                <Icon name="backup" /> Backup Now
                            </button>
                        )}
                        <button type="button" className="btn-primary" onClick={() => router.reload()}>
                            <Icon name="refresh" /> Refresh
                        </button>
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard compact label="Total Activities" value={stats.total} tone="primary" />
                <StatCard compact label="Today's Activities" value={stats.today} tone="green" />
                <StatCard compact label="Last 7 Days" value={stats.week} tone="blue" />
                <StatCard compact label="Last 30 Days" value={stats.month} tone="purple" />
            </div>

            <div className="panel mb-6 p-4">
                <h2 className="mb-3 font-semibold text-slate-900">Filters</h2>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <label className="flex flex-col text-xs text-slate-600">
                        Activity
                        <select
                            className="field-input-sm mt-1"
                            value={draft.activity ?? ''}
                            onChange={(e) => setDraft({ ...draft, activity: e.target.value })}
                        >
                            <option value="">Everything</option>
                            <option value="changes">Changes only</option>
                            <option value="access">Views and downloads only</option>
                        </select>
                    </label>
                    <label className="flex flex-col text-xs text-slate-600">
                        Record type
                        <select
                            className="field-input-sm mt-1"
                            value={draft.type ?? ''}
                            onChange={(e) => setDraft({ ...draft, type: e.target.value })}
                        >
                            <option value="">All records</option>
                            {types.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col text-xs text-slate-600">
                        By
                        <select
                            className="field-input-sm mt-1"
                            value={draft.user ?? ''}
                            onChange={(e) => setDraft({ ...draft, user: e.target.value })}
                        >
                            <option value="">Anyone</option>
                            {users.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col text-xs text-slate-600">
                        Start date
                        <input
                            type="date"
                            className="field-input-sm mt-1"
                            value={draft.from ?? ''}
                            onChange={(e) => setDraft({ ...draft, from: e.target.value })}
                        />
                    </label>
                    <label className="flex flex-col text-xs text-slate-600">
                        End date
                        <input
                            type="date"
                            className="field-input-sm mt-1"
                            value={draft.to ?? ''}
                            onChange={(e) => setDraft({ ...draft, to: e.target.value })}
                        />
                    </label>
                </div>
                <div className="mt-4 flex gap-3">
                    <button type="button" className="btn-primary" onClick={apply}>
                        Apply Filters
                    </button>
                    <button
                        type="button"
                        className="btn-secondary"
                        onClick={() => {
                            setDraft({});
                            router.get('/audit-log', {}, { replace: true });
                        }}
                    >
                        Clear
                    </button>
                </div>
            </div>

            <div
                className={cn('panel overflow-hidden transition-opacity duration-150', reloading && 'pointer-events-none opacity-50')}
                aria-busy={reloading}
            >
                <div className="relative overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="table-head">
                            <tr>
                                <th className="px-6 py-3 font-medium">Timestamp</th>
                                <th className="px-6 py-3 font-medium">User</th>
                                <th className="px-6 py-3 font-medium">Action</th>
                                <th className="px-6 py-3 font-medium">Record</th>
                                <th className="px-6 py-3 font-medium">Details</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 align-top">
                            {logs.data.map((l) => (
                                <tr key={l.id} className="hover:bg-slate-50">
                                    <td className="px-6 py-4 whitespace-nowrap text-slate-600">{when.format(new Date(l.at))}</td>
                                    <td className="px-6 py-4 whitespace-nowrap text-slate-900">{l.user}</td>
                                    <td className="px-6 py-4">
                                        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${tones[l.event]}`}>
                                            {verbs[l.event]}
                                        </span>
                                    </td>
                                    <td className="px-6 py-4">
                                        <p className="text-xs text-slate-500">{l.subject_type}</p>
                                        <p className="font-medium text-slate-900">{l.subject}</p>
                                    </td>
                                    <td className="px-6 py-4 text-slate-600">
                                        {l.event === 'updated' ? (
                                            <ul className="space-y-0.5">
                                                {l.changes.map((c, i) => (
                                                    <li key={i}>
                                                        {c.field}: <span className="text-slate-400 line-through">{c.old ?? 'blank'}</span> →{' '}
                                                        <span className="font-medium text-slate-900">{c.new ?? 'blank'}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : l.detail ? (
                                            <span>{l.detail}</span>
                                        ) : (
                                            <span>{l.changes.length} fields</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {logs.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No activity matches these filters.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <Pagination page={logs} />
            </div>
            <Modal open={backupOpen} onClose={() => setBackupOpen(false)} size="sm" bare>
                <div className="flex items-start gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                        <Icon name="backup" />
                    </div>
                    <div>
                        <h3 className="text-lg font-semibold text-slate-900">Download a Data Backup</h3>
                        <p className="text-xs text-slate-500">A ZIP file of spreadsheets you can keep offline</p>
                    </div>
                </div>
                <div className="mt-4 rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-800">
                    <p className="mb-2 font-semibold">The backup includes:</p>
                    <ul className="space-y-1">
                        {[
                            'All student and enrollment records',
                            'Document checklists and revision requests',
                            'Sections, strands and school years',
                            'User accounts (without passwords) and the audit log',
                        ].map((t) => (
                            <li key={t} className="flex items-center gap-2">
                                <Icon name="check" /> {t}
                            </li>
                        ))}
                    </ul>
                </div>
                <p className="mt-3 text-xs text-slate-500">It contains learners' personal data. Store it securely.</p>
                <div className="mt-6 flex justify-end gap-3">
                    <button type="button" className="btn-outline" onClick={() => setBackupOpen(false)}>
                        Cancel
                    </button>
                    <a href={route('backup')} className="btn bg-blue-600 text-white hover:bg-blue-700" onClick={() => setBackupOpen(false)}>
                        <Icon name="download" /> Backup Now
                    </a>
                </div>
            </Modal>
        </AppLayout>
    );
}
