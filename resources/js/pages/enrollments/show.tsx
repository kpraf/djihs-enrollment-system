import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field } from '@/components/form';
import { Icon } from '@/components/icon';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

export interface EnrollmentRecord {
    id: number;
    school_year: string;
    grade_level: number;
    strand_id: number | null;
    strand: { code: string; name: string } | null;
    learner_type: string;
    learner_type_label: string;
    is_irregular: boolean;
    status: 'enrolled' | 'cancelled';
    weight_kg: string | null;
    height_m: string | null;
    remarks: string | null;
    encoded_on: string | null;
    encoder: string | null;
    cancelled_at: string | null;
    canceller: string | null;
    cancellation_reason: string | null;
    enrollment_version: number;
    student_version: number;
    student: Record<string, string | boolean | null> & { full_name: string; lrn: string | null; age: number | null };
}

export interface HistoryEntry {
    id: number;
    at: string;
    user: string;
    event: 'created' | 'updated' | 'deleted';
    subject_type: string;
    subject: string;
    changes: { field: string; old: string | null; new: string | null }[];
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

export default function ShowEnrollment({
    enrollment: e,
    history,
    canEdit,
}: {
    enrollment: EnrollmentRecord;
    history: HistoryEntry[];
    canEdit: boolean;
}) {
    const s = e.student;
    const [cancelOpen, setCancelOpen] = useState(false);
    const [restoreOpen, setRestoreOpen] = useState(false);
    const cancel = useForm({ reason: '' });
    const restore = useForm({});

    return (
        <AppLayout>
            <Head title={s.full_name} />
            <PageHeader
                title={s.full_name}
                description={
                    <span className="flex flex-wrap items-center gap-3">
                        <span className="font-mono">LRN {s.lrn ?? 'missing'}</span>
                        <StatusBadge status={e.status} />
                    </span>
                }
                actions={
                    <>
                        <Link href={route('enrollments.index')} className="btn-outline">
                            <Icon name="chevron_left" /> Back to list
                        </Link>
                        {canEdit && (
                            <Link href={route('enrollments.edit', e.id)} className="btn-primary">
                                <Icon name="edit" /> Edit
                            </Link>
                        )}
                    </>
                }
            />

            {e.status === 'cancelled' && (
                <div className="mb-6 rounded-lg border border-slate-300 bg-slate-100 p-4 text-sm text-slate-700">
                    Cancelled{e.canceller ? ` by ${e.canceller}` : ''}
                    {e.cancelled_at ? ` on ${when.format(new Date(e.cancelled_at))}` : ''}
                    {e.cancellation_reason ? `: ${e.cancellation_reason}` : '.'}
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div className="space-y-6 xl:col-span-2">
                    <section className="panel p-6">
                        <h3 className="section-title mb-4">Enrollment</h3>
                        <dl className="grid grid-cols-2 gap-4 md:grid-cols-3">
                            <Item label="School year" value={e.school_year} />
                            <Item label="Grade" value={`Grade ${e.grade_level}`} />
                            <Item label="Strand" value={e.strand ? `${e.strand.code}` : null} />
                            <Item label="Type of learner" value={`${e.is_irregular ? 'Irregular' : 'Regular'} · ${e.learner_type_label}`} />
                            <Item label="Weight" value={e.weight_kg ? `${e.weight_kg} kg` : null} />
                            <Item label="Height" value={e.height_m ? `${e.height_m} m` : null} />
                            <Item label="Encoded" value={joinName(e.encoded_on, e.encoder ? `by ${e.encoder}` : null)} />
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

                    {canEdit && (
                        <section className="panel flex flex-wrap items-center justify-between gap-4 p-6">
                            <div>
                                <h3 className="font-semibold text-slate-900">
                                    {e.status === 'enrolled' ? 'Cancel this enrollment' : 'Restore this enrollment'}
                                </h3>
                                <p className="text-sm text-slate-500">
                                    {e.status === 'enrolled'
                                        ? 'The learner stays on record but is no longer counted as enrolled.'
                                        : 'Count this learner as enrolled again.'}
                                </p>
                            </div>
                            {e.status === 'enrolled' ? (
                                <button type="button" className="btn-danger" onClick={() => setCancelOpen(true)}>
                                    <Icon name="cancel" /> Cancel Enrollment
                                </button>
                            ) : (
                                <button type="button" className="btn-outline" onClick={() => setRestoreOpen(true)}>
                                    <Icon name="restore" /> Restore Enrollment
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
                open={cancelOpen}
                onClose={() => setCancelOpen(false)}
                onConfirm={() => {
                    // Always send the version on screen now. The form's own copy
                    // can be stale after an earlier save on this page.
                    cancel.transform((d) => ({ ...d, enrollment_version: e.enrollment_version }));
                    cancel.post(route('enrollments.cancel', e.id), { onSuccess: () => setCancelOpen(false), preserveScroll: true });
                }}
                title="Cancel this enrollment?"
                message={`${s.full_name} will no longer be counted as enrolled. You can restore it later.`}
                confirmLabel="Cancel Enrollment"
                icon="cancel"
                processing={cancel.processing}
            >
                <div className="mt-6 text-left">
                    <Field label="Reason" required error={cancel.errors.reason}>
                        {(p) => (
                            <input
                                {...p}
                                className="field-input"
                                value={cancel.data.reason}
                                onChange={(ev) => cancel.setData('reason', ev.target.value)}
                                placeholder="e.g., Transferred to another school"
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
                title="Restore this enrollment?"
                message={`${s.full_name} will be counted as enrolled again.`}
                confirmLabel="Restore"
                icon="restore"
                tone="primary"
                processing={restore.processing}
            />
        </AppLayout>
    );
}
