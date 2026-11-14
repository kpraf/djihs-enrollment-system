import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Icon } from './icon';
import { StatusBadge } from './status-badge';

export interface Revision {
    id: number;
    enrollment_id: number;
    learner: string | null;
    status: 'pending' | 'approved' | 'rejected';
    requester: string | null;
    reviewer: string | null;
    justification: string;
    review_notes: string | null;
    created_at: string;
    reviewed_at: string | null;
    changes: { field: string; old: string | null; new: string | null }[];
}

const when = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' });

/**
 * One revision request: what an adviser wants to change and why, with
 * Approve and Reject for registrars and ICT coordinators.
 */
export function RevisionCard({ revision: r, canDecide, showLearner = false }: { revision: Revision; canDecide: boolean; showLearner?: boolean }) {
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);

    const decide = (action: 'approve' | 'reject') => {
        setBusy(true);
        router.post(route(`revisions.${action}`, r.id), { notes }, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <div className="panel p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    {showLearner && (
                        <Link
                            href={route('enrollments.show', r.enrollment_id)}
                            className="hover:text-primary font-semibold text-slate-900 hover:underline"
                        >
                            {r.learner}
                        </Link>
                    )}
                    <p className="text-sm text-slate-500">
                        Requested by {r.requester ?? 'unknown'} on {when.format(new Date(r.created_at))}
                    </p>
                </div>
                <StatusBadge status={r.status} />
            </div>
            <ul className="mt-3 space-y-1 text-sm text-slate-700">
                {r.changes.map((c, i) => (
                    <li key={i}>
                        <span className="font-medium">{c.field}:</span> <span className="text-slate-400 line-through">{c.old ?? 'blank'}</span> →{' '}
                        <span className="font-semibold text-slate-900">{c.new ?? 'blank'}</span>
                    </li>
                ))}
            </ul>
            <p className="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
                <span className="font-medium">Reason: </span>
                {r.justification}
            </p>
            {r.status !== 'pending' && (
                <p className="mt-2 text-sm text-slate-500">
                    {r.status === 'approved' ? 'Approved' : 'Rejected'} by {r.reviewer}
                    {r.reviewed_at ? ` on ${when.format(new Date(r.reviewed_at))}` : ''}
                    {r.review_notes ? `: ${r.review_notes}` : ''}
                </p>
            )}
            {canDecide && r.status === 'pending' && (
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <input
                        className="field-input-sm min-w-0 flex-1"
                        placeholder="Note to the adviser (required to reject)"
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        aria-label="Review note"
                    />
                    <button type="button" className="btn-primary" disabled={busy} onClick={() => decide('approve')}>
                        <Icon name="check" /> Approve changes
                    </button>
                    <button type="button" className="btn-danger" disabled={busy || !notes.trim()} onClick={() => decide('reject')}>
                        Reject
                    </button>
                </div>
            )}
        </div>
    );
}
