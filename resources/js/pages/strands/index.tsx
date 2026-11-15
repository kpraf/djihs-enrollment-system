import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field, SelectInput, TextInput } from '@/components/form';
import { Icon } from '@/components/icon';
import { Modal } from '@/components/modal';
import { PageHeader } from '@/components/page-header';
import { RowActionButton } from '@/components/row-action';
import { Switch } from '@/components/switch';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface StrandRow {
    id: number;
    code: string;
    name: string;
    track: string;
    description: string | null;
    is_active: boolean;
    sections_count: number;
    enrollments_count: number;
}

export default function Strands({ strands, tracks }: { strands: StrandRow[]; tracks: Option[] }) {
    const [editing, setEditing] = useState<StrandRow | 'new' | null>(null);
    const [deleting, setDeleting] = useState<StrandRow | null>(null);
    const [deactivating, setDeactivating] = useState<StrandRow | null>(null);
    const setActive = (s: StrandRow, active: boolean, onFinish?: () => void) =>
        router.put(route('strands.set-active', s.id), { active }, { preserveScroll: true, onFinish });

    return (
        <AppLayout>
            <Head title="Strands" />
            <PageHeader
                title="Strands"
                description="Senior High School tracks and strands offered by the school."
                actions={
                    <button type="button" className="btn-primary" onClick={() => setEditing('new')}>
                        <Icon name="add" /> Add New Strand
                    </button>
                }
            />

            <div className="panel overflow-hidden">
                <div className="relative overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="table-head">
                            <tr>
                                <th className="px-6 py-3 font-medium">Code</th>
                                <th className="px-6 py-3 font-medium">Strand Name</th>
                                <th className="px-6 py-3 font-medium">Category</th>
                                <th className="px-6 py-3 text-right font-medium">Sections</th>
                                <th className="px-6 py-3 text-right font-medium">Students</th>
                                <th className="px-6 py-3 font-medium">Offered</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {strands.map((s) => (
                                <tr key={s.id} className="hover:bg-slate-50">
                                    <td className="px-6 py-4 font-mono font-semibold text-slate-900">{s.code}</td>
                                    <td className="px-6 py-4 text-slate-700">{s.name}</td>
                                    <td className="px-6 py-4 text-slate-600">{s.track === 'tvl' ? 'TVL' : 'Academic'}</td>
                                    <td className="px-6 py-4 text-right tabular-nums">{s.sections_count}</td>
                                    <td className="px-6 py-4 text-right tabular-nums">{s.enrollments_count}</td>
                                    <td className="px-6 py-4">
                                        <Switch
                                            checked={s.is_active}
                                            label={`${s.code} offered`}
                                            onText="Active"
                                            offText="Inactive"
                                            onChange={() => (s.is_active ? setDeactivating(s) : setActive(s, true))}
                                        />
                                    </td>
                                    <td className="px-6 py-4">
                                        <div className="flex flex-wrap justify-end gap-2">
                                            <RowActionButton icon="edit" text="Edit" srSuffix={s.code} onClick={() => setEditing(s)} />
                                            <RowActionButton
                                                icon="delete"
                                                text="Delete"
                                                srSuffix={s.code}
                                                tone="danger"
                                                disabled={s.sections_count + s.enrollments_count > 0}
                                                title={
                                                    s.sections_count + s.enrollments_count > 0 ? 'In use. Switch it to Inactive instead.' : undefined
                                                }
                                                onClick={() => setDeleting(s)}
                                            />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <StrandDialog
                key={editing === 'new' ? 'new' : (editing?.id ?? 'none')}
                strand={editing}
                tracks={tracks}
                onClose={() => setEditing(null)}
            />
            <ConfirmDialog
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={() =>
                    deleting && router.delete(route('strands.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })
                }
                title={`Delete ${deleting?.code ?? ''}?`}
                message="This strand is not used by any section or learner."
                confirmLabel="Delete"
                icon="delete"
            />
            <ConfirmDialog
                open={deactivating !== null}
                onClose={() => setDeactivating(null)}
                onConfirm={() => deactivating && setActive(deactivating, false, () => setDeactivating(null))}
                title={`Stop offering ${deactivating?.code ?? ''}?`}
                message="It will no longer appear when encoding learners or creating sections. Existing sections and learners keep it, and you can turn it back on anytime."
                confirmLabel="Yes, make inactive"
                icon="toggle_off"
            />
        </AppLayout>
    );
}

function StrandDialog({ strand, tracks, onClose }: { strand: StrandRow | 'new' | null; tracks: Option[]; onClose: () => void }) {
    const existing = strand !== 'new' ? strand : null;
    const { data, setData, post, put, processing, errors } = useForm({
        code: existing?.code ?? '',
        name: existing?.name ?? '',
        track: existing?.track ?? 'academic',
        description: existing?.description ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (existing) put(route('strands.update', existing.id), opts);
        else post(route('strands.store'), opts);
    };

    return (
        <Modal open={strand !== null} onClose={onClose} title={existing ? `Edit ${existing.code}` : 'Add New Strand'} size="sm">
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field label="Strand Code" required error={errors.code} hint="For example HUMSS or ICT-CSS. The importer matches on this.">
                    {(p) => <TextInput {...p} value={data.code} onChange={(e) => setData('code', e.target.value)} data-autofocus />}
                </Field>
                <Field label="Strand Name" required error={errors.name}>
                    {(p) => <TextInput {...p} value={data.name} onChange={(e) => setData('name', e.target.value)} />}
                </Field>
                <Field label="Category" required error={errors.track}>
                    {(p) => (
                        <SelectInput {...p} value={data.track} onChange={(e) => setData('track', e.target.value)}>
                            {tracks.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </SelectInput>
                    )}
                </Field>
                <Field label="Description" error={errors.description}>
                    {(p) => (
                        <textarea
                            {...p}
                            rows={3}
                            className="field-input"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                    )}
                </Field>
                <div className="flex gap-3 pt-2">
                    <button type="button" className="btn-outline flex-1 py-3" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" className="btn-primary flex-1 py-3" disabled={processing}>
                        Save Strand
                    </button>
                </div>
            </form>
        </Modal>
    );
}
