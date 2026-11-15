import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field } from '@/components/form';
import { Icon } from '@/components/icon';
import { PageHeader } from '@/components/page-header';
import { RevisionCard, type Revision } from '@/components/revision-card';
import { StatusBadge } from '@/components/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export interface EnrollmentRecord {
    id: number;
    school_year: string;
    grade_level: number;
    strand_id: number | null;
    strand: { code: string; name: string } | null;
    section: string | null;
    learner_type: string;
    learner_type_label: string;
    is_irregular: boolean;
    status: string;
    status_label: string;
    weight_kg: string | null;
    height_m: string | null;
    remarks: string | null;
    encoded_on: string | null;
    encoder: string | null;
    reviewer: string | null;
    reviewed_at: string | null;
    status_changed_at: string | null;
    status_changer: string | null;
    status_reason: string | null;
    privacy_notice_at: string | null;
    privacy_notice_by: string | null;
    documents_complete: boolean;
    enrollment_version: number;
    student_version: number;
    student: Record<string, string | boolean | null> & { full_name: string; lrn: string | null; age: number | null };
}

export interface HistoryEntry {
    id: number;
    at: string;
    user: string;
    event: 'created' | 'updated' | 'deleted' | 'viewed' | 'exported';
    subject_type: string;
    subject: string;
    changes: { field: string; old: string | null; new: string | null }[];
    /** For views and downloads: what was seen, such as "Learner record". */
    detail: string | null;
}

interface DocumentRow {
    type: string;
    label: string;
    is_submitted: boolean;
    is_verified: boolean;
    notes: string | null;
    updated_by: string | null;
}

interface Props {
    enrollment: EnrollmentRecord;
    documents: DocumentRow[];
    revisions: Revision[];
    history: HistoryEntry[];
    can: { edit: boolean; requestRevision: boolean; review: boolean; documents: boolean; approveRevisions: boolean };
    leavingStatuses: { value: string; label: string }[];
}

const when = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' });

function Item({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</dt>
            <dd className="mt-0.5 text-base text-slate-900">
                {value === null || value === '' || value === undefined ? <span className="text-slate-400">—</span> : value}
            </dd>
        </div>
    );
}

function joinName(...parts: (string | boolean | null | undefined)[]) {
    return parts.filter((p) => typeof p === 'string' && p).join(' ') || null;
}

export default function ShowEnrollment({ enrollment: e, documents, revisions, history, can, leavingStatuses }: Props) {
    const s = e.student;
    const [leaveOpen, setLeaveOpen] = useState(false);
    const [restoreOpen, setRestoreOpen] = useState(false);
    const leave = useForm({ status: 'cancelled', reason: '' });
    const restore = useForm({});
    const isLeft = ['cancelled', 'dropped', 'transferred_out', 'rejected'].includes(e.status);

    return (
        <AppLayout>
            <Head title={s.full_name} />
            <PageHeader
                title={s.full_name}
                description={
                    <span className="flex flex-wrap items-center gap-3">
                        <span className="font-mono">LRN {s.lrn ?? 'missing'}</span>
                        <StatusBadge status={e.status} />
                        {!e.documents_complete && <span className="text-sm text-amber-700">Documents incomplete</span>}
                    </span>
                }
                actions={
                    <>
                        <Link href={route('enrollments.index')} className="btn-outline">
                            <Icon name="chevron_left" /> Back to list
                        </Link>
                        <a href={route('enrollments.data-record', e.id)} target="_blank" rel="noreferrer" className="btn-outline">
                            <Icon name="privacy_tip" /> Learner Data Record
                        </a>
                        {(can.edit || can.requestRevision) && (
                            <Link href={route('enrollments.edit', e.id)} className="btn-primary">
                                <Icon name="edit" /> {can.edit ? 'Edit' : 'Request Changes'}
                            </Link>
                        )}
                    </>
                }
            />

            {e.status === 'pending' && (
                <div className="mb-6 flex flex-wrap items-center gap-3 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-900">
                    <Icon name="pending" /> Waiting for review.
                    {can.review && (
                        <Link href={route('reviews.index')} className="font-semibold underline">
                            Go to Review Enrollments
                        </Link>
                    )}
                </div>
            )}

            {isLeft && (
                <div className="mb-6 rounded-lg border border-slate-300 bg-slate-100 p-4 text-sm text-slate-700">
                    {e.status_label}
                    {e.status_changer ? ` by ${e.status_changer}` : ''}
                    {e.status_changed_at ? ` on ${when.format(new Date(e.status_changed_at))}` : ''}
                    {e.status_reason ? `: ${e.status_reason}` : '.'}
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div className="space-y-6 xl:col-span-2">
                    <section className="panel p-6">
                        <h3 className="section-title mb-4">Enrollment</h3>
                        <dl className="grid grid-cols-2 gap-4 md:grid-cols-3">
                            <Item label="School year" value={e.school_year} />
                            <Item label="Grade" value={`Grade ${e.grade_level}`} />
                            <Item label="Strand" value={e.strand?.code} />
                            <Item label="Section" value={e.section} />
                            <Item label="Type of learner" value={`${e.is_irregular ? 'Irregular' : 'Regular'} · ${e.learner_type_label}`} />
                            <Item label="Weight" value={e.weight_kg ? `${e.weight_kg} kg` : null} />
                            <Item label="Height" value={e.height_m ? `${e.height_m} m` : null} />
                            <Item label="Encoded" value={joinName(e.encoded_on, e.encoder ? `by ${e.encoder}` : null)} />
                            <Item
                                label="Reviewed"
                                value={e.reviewed_at ? joinName(when.format(new Date(e.reviewed_at)), e.reviewer ? `by ${e.reviewer}` : null) : null}
                            />
                            <Item
                                label="Privacy notice"
                                value={
                                    e.privacy_notice_at
                                        ? joinName(
                                              when.format(new Date(e.privacy_notice_at)),
                                              e.privacy_notice_by ? `recorded by ${e.privacy_notice_by}` : null,
                                          )
                                        : 'Not recorded'
                                }
                            />
                            <Item label="Remarks" value={e.remarks} />
                        </dl>
                    </section>

                    <section className="panel p-6">
                        <h3 className="section-title mb-4">Learner&apos;s Information</h3>
                        <dl className="grid grid-cols-2 gap-4 md:grid-cols-3">
                            <Item label="Birthdate" value={s.birthdate as string} />
                            <Item label="Age" value={s.age} />
                            <Item label="Sex" value={typeof s.sex === 'string' ? s.sex.charAt(0).toUpperCase() + s.sex.slice(1) : null} />
                            <Item label="Religion" value={s.religion as string} />
                            <Item label="Mother tongue" value={s.mother_tongue as string} />
                            <Item label="IP community" value={s.is_indigenous ? (s.indigenous_group as string) || 'Yes' : 'No'} />
                            <Item label="With disability" value={s.is_pwd ? (s.disability as string) || 'Yes' : 'No'} />
                            <Item label="4Ps beneficiary" value={s.is_4ps_beneficiary ? 'Yes' : 'No'} />
                            <Item label="Address" value={joinName(s.house_number, s.street, s.barangay, s.municipality, s.province, s.zip_code)} />
                        </dl>
                    </section>

                    <section className="panel p-6">
                        <h3 className="section-title mb-4">Parent/Guardian Information</h3>
                        <dl className="grid grid-cols-2 gap-4 md:grid-cols-3">
                            <Item label="Father" value={joinName(s.father_first_name, s.father_middle_name, s.father_last_name)} />
                            <Item label="Mother (maiden name)" value={joinName(s.mother_first_name, s.mother_middle_name, s.mother_last_name)} />
                            <Item
                                label="Guardian"
                                value={joinName(
                                    s.guardian_first_name,
                                    s.guardian_middle_name,
                                    s.guardian_last_name,
                                    s.guardian_relationship ? `(${s.guardian_relationship})` : null,
                                )}
                            />
                            <Item label="Contact number" value={s.contact_number as string} />
                        </dl>
                    </section>

                    <DocumentChecklist enrollmentId={e.id} documents={documents} editable={can.documents} complete={e.documents_complete} />

                    {revisions.length > 0 && (
                        <section className="space-y-3">
                            <h3 className="text-lg font-semibold text-slate-800">Revision Requests</h3>
                            {revisions.map((r) => (
                                <RevisionCard key={r.id} revision={r} canDecide={can.approveRevisions} />
                            ))}
                        </section>
                    )}

                    {can.edit && (
                        <section className="panel flex flex-wrap items-center justify-between gap-4 p-6">
                            <div>
                                <h3 className="font-semibold text-slate-900">{isLeft ? 'Restore this learner' : 'Learner leaving the school'}</h3>
                                <p className="text-sm text-slate-500">
                                    {isLeft
                                        ? e.status === 'rejected'
                                            ? 'Send this enrollment back for review.'
                                            : 'Count this learner as enrolled again.'
                                        : 'Cancel the enrollment, or mark the learner as dropped out or transferred out. Their section seat is freed.'}
                                </p>
                            </div>
                            {isLeft ? (
                                <button type="button" className="btn-outline" onClick={() => setRestoreOpen(true)}>
                                    <Icon name="restore" /> Restore
                                </button>
                            ) : (
                                <button type="button" className="btn-danger" onClick={() => setLeaveOpen(true)}>
                                    <Icon name="person_off" /> Change Status
                                </button>
                            )}
                        </section>
                    )}
                </div>

                <section className="panel h-fit p-6">
                    <h3 className="section-title mb-4 flex items-center gap-2">
                        <Icon name="history" /> History
                    </h3>
                    {history.length === 0 && <p className="text-sm text-slate-500">No changes recorded.</p>}
                    <ol className="space-y-4">
                        {history.map((h) => (
                            <li key={h.id} className="border-l-2 border-slate-200 pl-4">
                                <p className="text-sm text-slate-900">
                                    <span className="font-semibold">{h.user}</span> {h.event === 'created' ? 'created the' : 'updated the'}{' '}
                                    {h.subject_type.toLowerCase()}
                                </p>
                                <p className="text-xs text-slate-500">{when.format(new Date(h.at))}</p>
                                {h.event === 'updated' && (
                                    <ul className="mt-1 space-y-0.5 text-sm text-slate-600">
                                        {h.changes.map((c, i) => (
                                            <li key={i}>
                                                {c.field}: <span className="text-slate-400 line-through">{c.old ?? 'blank'}</span> →{' '}
                                                <span className="font-medium">{c.new ?? 'blank'}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ol>
                </section>
            </div>

            <ConfirmDialog
                open={leaveOpen}
                onClose={() => setLeaveOpen(false)}
                onConfirm={() => {
                    // Always send the version on screen now; the form's copy can be stale.
                    leave.transform((d) => ({ ...d, enrollment_version: e.enrollment_version }));
                    leave.post(route('enrollments.leave', e.id), { onSuccess: () => setLeaveOpen(false), preserveScroll: true });
                }}
                title="Change this learner's status?"
                message={`${s.full_name} will no longer be counted as enrolled. You can restore this later.`}
                confirmLabel="Change Status"
                icon="person_off"
                processing={leave.processing}
            >
                <div className="mt-6 space-y-4 text-left">
                    <Field label="New status" required error={leave.errors.status}>
                        {(p) => (
                            <select
                                {...p}
                                className="field-input"
                                value={leave.data.status}
                                onChange={(ev) => leave.setData('status', ev.target.value)}
                            >
                                {leavingStatuses.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                        )}
                    </Field>
                    <Field label="Reason" required error={leave.errors.reason}>
                        {(p) => (
                            <input
                                {...p}
                                className="field-input"
                                value={leave.data.reason}
                                onChange={(ev) => leave.setData('reason', ev.target.value)}
                                placeholder="e.g., Moved to another school"
                            />
                        )}
                    </Field>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={restoreOpen}
                onClose={() => setRestoreOpen(false)}
                onConfirm={() => {
                    restore.transform(() => ({ enrollment_version: e.enrollment_version }));
                    restore.post(route('enrollments.restore', e.id), { onSuccess: () => setRestoreOpen(false), preserveScroll: true });
                }}
                title="Restore this learner?"
                message={
                    e.status === 'rejected' ? 'The enrollment goes back to Review Enrollments.' : `${s.full_name} will be counted as enrolled again.`
                }
                confirmLabel="Restore"
                icon="restore"
                tone="primary"
                processing={restore.processing}
            />
        </AppLayout>
    );
}

function DocumentChecklist({
    enrollmentId,
    documents,
    editable,
    complete,
}: {
    enrollmentId: number;
    documents: DocumentRow[];
    editable: boolean;
    complete: boolean;
}) {
    // Ticks show at once and are saved in the background.
    const [rows, setRows] = useState(documents);
    useEffect(() => setRows(documents), [documents]);

    const save = (d: DocumentRow, changes: Partial<DocumentRow>) => {
        const next = { ...d, ...changes };
        next.is_verified = next.is_submitted && next.is_verified;
        setRows((current) => current.map((r) => (r.type === d.type ? next : r)));
        router.put(
            route('enrollments.documents', enrollmentId),
            { document_type: d.type, is_submitted: next.is_submitted, is_verified: next.is_verified, notes: next.notes ?? '' },
            { preserveScroll: true, onError: () => setRows(documents) },
        );
    };

    return (
        <section className="panel p-6">
            <div className="mb-4 flex items-center justify-between border-b border-slate-200 pb-2">
                <h3 className="text-lg font-semibold text-slate-800">Document Checklist</h3>
                {complete ? <StatusBadge status="approved" /> : <span className="text-sm font-medium text-amber-700">Incomplete</span>}
            </div>
            <p className="mb-4 text-sm text-slate-500">
                Required: a birth certificate (PSA or local) and the report card. Transferees also need Form 137.
            </p>
            <div className="divide-y divide-slate-100">
                {rows.map((d) => (
                    <div key={d.type} className="flex flex-wrap items-center gap-x-6 gap-y-2 py-3">
                        <span className="min-w-[14rem] flex-1 text-sm font-medium text-slate-900">{d.label}</span>
                        <label className="flex items-center gap-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                className="checkbox"
                                checked={d.is_submitted}
                                disabled={!editable}
                                onChange={(ev) => save(d, { is_submitted: ev.target.checked })}
                            />
                            Submitted
                        </label>
                        <label className="flex items-center gap-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                className="checkbox"
                                checked={d.is_verified}
                                disabled={!editable || !d.is_submitted}
                                onChange={(ev) => save(d, { is_verified: ev.target.checked })}
                            />
                            Verified
                        </label>
                        {editable ? (
                            <input
                                className="field-input-sm w-full sm:w-56"
                                defaultValue={d.notes ?? ''}
                                placeholder="Notes"
                                aria-label={`Notes for ${d.label}`}
                                onBlur={(ev) => ev.target.value !== (d.notes ?? '') && save(d, { notes: ev.target.value })}
                            />
                        ) : (
                            d.notes && <span className="text-sm text-slate-500">{d.notes}</span>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}
