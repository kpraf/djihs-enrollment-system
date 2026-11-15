import { CapacityBar } from '@/components/capacity-bar';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field, SelectInput, TextInput } from '@/components/form';
import { Icon } from '@/components/icon';
import { Modal } from '@/components/modal';
import { PageHeader } from '@/components/page-header';
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

            <div className="panel overflow-hidden">
                <div className="relative overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="table-head">
                            <tr>
                                <th className="px-6 py-3 font-medium">Section Name</th>
                                <th className="px-6 py-3 font-medium">Adviser</th>
                                <th className="px-6 py-3 font-medium">Grade / Strand</th>
                                <th className="px-6 py-3 font-medium">Capacity</th>
                                <th className="px-6 py-3 font-medium">Subject Teachers</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {sections.map((s) => (
                                <tr key={s.id} className="hover:bg-slate-50">
                                    <td className="px-6 py-4 font-semibold text-slate-900">{s.name}</td>
                                    <td className="px-6 py-4 text-slate-600">{s.adviser ?? <span className="text-amber-700">No adviser</span>}</td>
                                    <td className="px-6 py-4 whitespace-nowrap text-slate-600">
                                        Grade {s.grade_level}
                                        {s.strand ? ` · ${s.strand}` : ''}
                                    </td>
                                    <td className="px-6 py-4">
                                        <CapacityBar filled={s.filled} capacity={s.capacity} />
                                    </td>
                                    <td className="px-6 py-4">
                                        <button
                                            type="button"
                                            className="text-primary text-sm font-medium hover:underline"
                                            onClick={() => setTeachersOf(s.id)}
                                        >
                                            {s.subject_teachers.length} assigned
                                        </button>
                                    </td>
                                    <td className="px-6 py-4">
                                        <StatusBadge status={s.is_active ? 'active' : 'inactive'} />
                                    </td>
                                    <td className="px-6 py-4">
                                        <div className="flex justify-end gap-1">
                                            <a
                                                href={route('sections.sf1', s.id)}
                                                className="hover:text-primary rounded-lg p-2 text-slate-400 hover:bg-slate-100"
                                                title="Export SF1"
                                            >
                                                <Icon name="description" label={`Export SF1 for ${s.name}`} />
                                            </a>
                                            <button
                                                type="button"
                                                className="hover:text-primary rounded-lg p-2 text-slate-400 hover:bg-slate-100"
                                                onClick={() => setEditing(s)}
                                            >
                                                <Icon name="edit" label={`Edit ${s.name}`} />
                                            </button>
                                            <button
                                                type="button"
                                                className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-red-600"
                                                onClick={() => setDeleting(s)}
                                            >
                                                <Icon name="delete" label={`Delete ${s.name}`} />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {sections.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-6 py-12 text-center text-slate-500">
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
        capacity: String(existing?.capacity ?? 45),
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
                        Save Section
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
                        <button
                            type="button"
                            className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                            onClick={() => router.delete(route('sections.teachers.destroy', t.id), { preserveScroll: true })}
                        >
                            <Icon name="close" label={`Remove ${t.subject_name}`} />
                        </button>
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
                    <Icon name="add" /> Assign Subject Teacher
                </button>
            </form>
        </Modal>
    );
}
