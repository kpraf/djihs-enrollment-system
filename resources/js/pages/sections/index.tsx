import { ActionMenu, type ActionMenuItem } from '@/components/action-menu';
import { CapacityBar } from '@/components/capacity-bar';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field, SelectInput, TextInput } from '@/components/form';
import { Icon } from '@/components/icon';
import { Modal } from '@/components/modal';
import { PageHeader } from '@/components/page-header';
import { RowActionButton } from '@/components/row-action';
import { BusyLabel } from '@/components/spinner';
import { StatusBadge } from '@/components/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface SubjectTeacher {
    id: number;
    subject_name: string;
    subject_code: string | null;
    teacher: string | null;
    user_id: number;
}

interface SectionRow {
    id: number;
    name: string;
    grade_level: number;
    strand_id: number | null;
    strand: string | null;
    adviser_id: number | null;
    adviser: string | null;
    capacity: number;
    is_active: boolean;
    filled: number;
    subject_teachers: SubjectTeacher[];
}

interface Props {
    sections: SectionRow[];
    schoolYears: { id: number; name: string; is_active: boolean }[];
    selectedYear: { id: number; name: string } | null;
    options: {
        grades: Option<number>[];
        strands: { id: number; code: string }[];
        advisers: { id: number; name: string }[];
        subjectTeachers: { id: number; name: string }[];
    };
}

/** Every form and list a section can be downloaded as. */
function sectionExports(id: number): ActionMenuItem[] {
    return [
        { label: 'SF1 – School Register', hint: 'Excel · DepEd School Form 1', icon: 'description', href: route('sections.sf1', id) },
        { label: 'SF8 – Health and Nutrition', hint: 'Excel · BMI and height-for-age', icon: 'monitor_weight', href: route('sections.sf8', id) },
        { label: 'Class list', hint: 'Excel', icon: 'table_view', href: route('sections.export', { section: id, format: 'xlsx' }) },
        { label: 'Class list', hint: 'CSV', icon: 'download', href: route('sections.export', { section: id, format: 'csv' }) },
        {
            label: 'Print class list',
            hint: 'Opens a printable page; save it as PDF there',
            icon: 'print',
            href: route('sections.print', id),
            newTab: true,
        },
    ];
}

export default function Sections({ sections, schoolYears, selectedYear, options }: Props) {
    const [editing, setEditing] = useState<SectionRow | 'new' | null>(null);
    const [teachersOf, setTeachersOf] = useState<number | null>(null);
    const [deleting, setDeleting] = useState<SectionRow | null>(null);
    const teacherSection = sections.find((s) => s.id === teachersOf) ?? null;

    return (
        <AppLayout>
            <Head title="Sections" />
            <PageHeader
                title="Sections"
                description={selectedYear ? `Sections for school year ${selectedYear.name}.` : 'Create a school year first.'}
                actions={
                    <>
                        <select
                            className="field-input-sm h-10 w-auto"
                            value={selectedYear?.id ?? ''}
                            onChange={(e) => router.get('/sections', { school_year: e.target.value }, { preserveState: true })}
                            aria-label="School year"
                        >
                            {schoolYears.map((y) => (
                                <option key={y.id} value={y.id}>
                                    SY {y.name}
                                </option>
                            ))}
                        </select>
                        <button type="button" className="btn-primary" disabled={!selectedYear} onClick={() => setEditing('new')}>
                            <Icon name="add" /> Create New Section
                        </button>
                    </>
                }
            />

            {/* Phones, tablets and small laptops: one card per section, so nothing is scrolled out of view. */}
            <ul className="grid gap-3 md:grid-cols-2 xl:hidden" aria-label="Sections">
                {sections.map((s) => (
                    <li key={s.id} className="panel space-y-3 p-4">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <h2 className="text-base font-semibold text-slate-900">{s.name}</h2>
                                <p className="text-sm text-slate-600">
                                    Grade {s.grade_level}
                                    {s.strand ? ` · ${s.strand}` : ''}
                                </p>
                            </div>
                            <StatusBadge status={s.is_active ? 'active' : 'inactive'} />
                        </div>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
                            <dt className="text-slate-500">Adviser</dt>
                            <dd className="text-slate-800">{s.adviser ?? <span className="text-amber-700">No adviser</span>}</dd>
                            <dt className="text-slate-500">Subject teachers</dt>
                            <dd>
                                <button type="button" className="text-primary font-medium hover:underline" onClick={() => setTeachersOf(s.id)}>
                                    {s.subject_teachers.length} assigned
                                </button>
                            </dd>
                        </dl>
                        <CapacityBar filled={s.filled} capacity={s.capacity} />
                        <div className="flex gap-2 border-t border-slate-100 pt-3">
                            <ActionMenu
                                label="Export"
                                icon="download"
                                items={sectionExports(s.id)}
                                srSuffix={`for ${s.name}`}
                                className="flex-1 py-2"
                            />
                            <RowActionButton icon="edit" text="Edit" srSuffix={s.name} onClick={() => setEditing(s)} className="py-2" />
                            <RowActionButton
                                icon="delete"
                                text="Delete"
                                srSuffix={s.name}
                                tone="danger"
                                onClick={() => setDeleting(s)}
                                className="py-2"
                            />
                        </div>
                    </li>
                ))}
                {sections.length === 0 && (
                    <li className="panel p-8 text-center text-slate-500 md:col-span-2">No sections yet for this school year.</li>
                )}
            </ul>

            <div className="panel hidden overflow-hidden xl:block">
                <div className="relative overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="table-head">
                            <tr>
                                <th className="px-4 py-3 font-medium">Section</th>
                                <th className="px-4 py-3 font-medium">Grade / Strand</th>
                                <th className="px-4 py-3 font-medium">Adviser and teachers</th>
                                <th className="px-4 py-3 font-medium">Capacity</th>
                                <th className="px-4 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {sections.map((s) => (
                                <tr key={s.id} className="hover:bg-slate-50">
                                    <td className="px-4 py-4">
                                        <p className="font-semibold text-slate-900">{s.name}</p>
                                        <div className="mt-1">
                                            <StatusBadge status={s.is_active ? 'active' : 'inactive'} />
                                        </div>
                                    </td>
                                    <td className="px-4 py-4 whitespace-nowrap text-slate-600">
                                        Grade {s.grade_level}
                                        {s.strand ? ` · ${s.strand}` : ''}
                                    </td>
                                    <td className="px-4 py-4">
                                        <p className="text-slate-700">{s.adviser ?? <span className="text-amber-700">No adviser</span>}</p>
                                        <button
                                            type="button"
                                            className="text-primary text-sm font-medium hover:underline"
                                            onClick={() => setTeachersOf(s.id)}
                                        >
                                            {s.subject_teachers.length} subject {s.subject_teachers.length === 1 ? 'teacher' : 'teachers'}
                                        </button>
                                    </td>
                                    <td className="px-4 py-4">
                                        <CapacityBar filled={s.filled} capacity={s.capacity} />
                                    </td>
                                    <td className="px-4 py-4">
                                        <div className="flex justify-end gap-2">
                                            <ActionMenu label="Export" icon="download" items={sectionExports(s.id)} srSuffix={`for ${s.name}`} />
                                            <RowActionButton icon="edit" text="Edit" srSuffix={s.name} onClick={() => setEditing(s)} />
                                            <RowActionButton
                                                icon="delete"
                                                text="Delete"
                                                srSuffix={s.name}
                                                tone="danger"
                                                onClick={() => setDeleting(s)}
                                            />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {sections.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No sections yet for this school year.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
            <p className="mt-4 text-sm text-slate-500">
                Place learners into sections on{' '}
                <Link href="/section-assignments" className="text-primary font-medium hover:underline">
                    Assign to Sections
                </Link>
                .
            </p>

            {selectedYear && (
                <SectionDialog
                    key={editing === 'new' ? 'new' : (editing?.id ?? 'none')}
                    section={editing}
                    yearId={selectedYear.id}
                    options={options}
                    onClose={() => setEditing(null)}
                />
            )}
            <TeachersDialog section={teacherSection} teachers={options.subjectTeachers} onClose={() => setTeachersOf(null)} />
            <ConfirmDialog
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={() =>
                    deleting && router.delete(route('sections.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })
                }
                title={`Delete section ${deleting?.name ?? ''}?`}
                message={
                    deleting?.filled
                        ? `It still has ${deleting.filled} learners, so it cannot be deleted. Move them out first.`
                        : 'This cannot be undone.'
                }
                confirmLabel="Delete"
                icon="delete"
            />
        </AppLayout>
    );
}

function SectionDialog({
    section,
    yearId,
    options,
    onClose,
}: {
    section: SectionRow | 'new' | null;
    yearId: number;
    options: Props['options'];
    onClose: () => void;
}) {
    const existing = section !== 'new' ? section : null;
    const { data, setData, post, put, processing, errors } = useForm({
        school_year_id: yearId,
        name: existing?.name ?? '',
        grade_level: String(existing?.grade_level ?? 11),
        strand_id: existing?.strand_id ? String(existing.strand_id) : '',
        adviser_id: existing?.adviser_id ? String(existing.adviser_id) : '',
        capacity: String(existing?.capacity ?? 40),
        is_active: existing?.is_active ?? true,
    });
    const shs = Number(data.grade_level) >= 11;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (existing) put(route('sections.update', existing.id), opts);
        else post(route('sections.store'), opts);
    };

    return (
        <Modal open={section !== null} onClose={onClose} title={existing ? `Edit ${existing.name}` : 'Create New Section'} size="sm">
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field label="Section Name" required error={errors.name}>
                    {(p) => (
                        <TextInput
                            {...p}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g., Integrity"
                            data-autofocus
                        />
                    )}
                </Field>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Grade Level" required error={errors.grade_level}>
                        {(p) => (
                            <SelectInput {...p} value={data.grade_level} onChange={(e) => setData('grade_level', e.target.value)}>
                                {options.grades.map((g) => (
                                    <option key={g.value} value={g.value}>
                                        {g.label}
                                    </option>
                                ))}
                            </SelectInput>
                        )}
                    </Field>
                    <Field label="Strand" required={shs} error={errors.strand_id}>
                        {(p) => (
                            <SelectInput {...p} value={data.strand_id} disabled={!shs} onChange={(e) => setData('strand_id', e.target.value)}>
                                <option value="">{shs ? 'Select strand' : 'Not applicable'}</option>
                                {options.strands.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.code}
                                    </option>
                                ))}
                            </SelectInput>
                        )}
                    </Field>
                </div>
                <Field label="Adviser" error={errors.adviser_id}>
                    {(p) => (
                        <SelectInput {...p} value={data.adviser_id} onChange={(e) => setData('adviser_id', e.target.value)}>
                            <option value="">No adviser yet</option>
                            {options.advisers.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </SelectInput>
                    )}
                </Field>
                <Field label="Capacity" required error={errors.capacity}>
                    {(p) => (
                        <TextInput
                            {...p}
                            type="number"
                            min={1}
                            max={80}
                            value={data.capacity}
                            onChange={(e) => setData('capacity', e.target.value)}
                        />
                    )}
                </Field>
                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" className="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                    Active (learners can be placed here)
                </label>
                <div className="flex gap-3 pt-2">
                    <button type="button" className="btn-outline flex-1 py-3" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" className="btn-primary flex-1 py-3" disabled={processing}>
                        <BusyLabel busy={processing} busyText="Saving…">
                            Save Section
                        </BusyLabel>
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function TeachersDialog({
    section,
    teachers,
    onClose,
}: {
    section: SectionRow | null;
    teachers: { id: number; name: string }[];
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({ user_id: '', subject_name: '', subject_code: '' });
    const [removing, setRemoving] = useState<number | null>(null);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!section) return;
        post(route('sections.teachers.store', section.id), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <Modal open={section !== null} onClose={onClose} title={`Subject Teachers · ${section?.name ?? ''}`} size="md">
            <ul className="mb-6 divide-y divide-slate-100 rounded-lg border border-slate-200">
                {section?.subject_teachers.map((t) => (
                    <li key={t.id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                        <span>
                            <span className="font-medium text-slate-900">{t.subject_name}</span>
                            {t.subject_code && <span className="text-slate-500"> ({t.subject_code})</span>}
                            <span className="block text-slate-600">{t.teacher}</span>
                        </span>
                        {removing === t.id ? (
                            <span className="flex shrink-0 items-center gap-2" role="group" aria-label={`Remove ${t.subject_name}?`}>
                                <span className="text-sm font-medium text-red-700">Remove?</span>
                                <button
                                    type="button"
                                    className="btn-danger px-3 py-1.5"
                                    onClick={() =>
                                        router.delete(route('sections.teachers.destroy', t.id), {
                                            preserveScroll: true,
                                            onFinish: () => setRemoving(null),
                                        })
                                    }
                                >
                                    Yes, remove
                                </button>
                                <button type="button" className="btn-outline px-3 py-1.5" onClick={() => setRemoving(null)}>
                                    No
                                </button>
                            </span>
                        ) : (
                            <RowActionButton icon="close" text="Remove" srSuffix={t.subject_name} tone="danger" onClick={() => setRemoving(t.id)} />
                        )}
                    </li>
                ))}
                {section?.subject_teachers.length === 0 && <li className="px-4 py-3 text-sm text-slate-500">No subject teachers yet.</li>}
            </ul>
            <form onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2" noValidate>
                <Field label="Subject" required error={errors.subject_name}>
                    {(p) => (
                        <TextInput
                            {...p}
                            value={data.subject_name}
                            onChange={(e) => setData('subject_name', e.target.value)}
                            placeholder="e.g., General Mathematics"
                        />
                    )}
                </Field>
                <Field label="Subject Code" error={errors.subject_code}>
                    {(p) => (
                        <TextInput
                            {...p}
                            value={data.subject_code}
                            onChange={(e) => setData('subject_code', e.target.value)}
                            placeholder="Optional"
                        />
                    )}
                </Field>
                <Field label="Teacher" required error={errors.user_id} className="sm:col-span-2">
                    {(p) => (
                        <SelectInput {...p} value={data.user_id} onChange={(e) => setData('user_id', e.target.value)}>
                            <option value="">Select teacher</option>
                            {teachers.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                </option>
                            ))}
                        </SelectInput>
                    )}
                </Field>
                <button type="submit" className="btn-primary sm:col-span-2" disabled={processing}>
                    <BusyLabel busy={processing} busyText="Assigning…">
                        <Icon name="add" /> Assign Subject Teacher
                    </BusyLabel>
                </button>
            </form>
        </Modal>
    );
}
