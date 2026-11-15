import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field, InputError, SelectInput, TextInput, YesNo } from '@/components/form';
import { Icon } from '@/components/icon';
import { describeDate, formatWhileTyping, parseTypedDate, toTypedDate } from '@/lib/typed-date';
import { cn } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo, useRef, useState } from 'react';
import { emptyLearner, learnerTypeChoices, toFormValues, type FormOptions, type LearnerForm } from './types';

interface LookupResult {
    student: (Record<string, unknown> & { full_name: string }) | null;
    enrollment: { id: number; summary: string; status: string } | null;
}

interface Props {
    mode: 'create' | 'edit';
    schoolYear: string;
    options: FormOptions;
    initial?: Partial<LearnerForm>;
    action: { method: 'post' | 'put'; url: string };
    cancelHref?: string;
    /** Advisers' edits are sent for approval with a reason. */
    asRevision?: boolean;
    /** The learner's current section, if any. A new grade or strand takes them out of it. */
    section?: string | null;
}

function ageFrom(birthdate: string): string {
    if (!birthdate) return '';
    const b = new Date(birthdate + 'T00:00:00');
    if (Number.isNaN(b.getTime())) return '';
    const now = new Date();
    let age = now.getFullYear() - b.getFullYear();
    if (now.getMonth() < b.getMonth() || (now.getMonth() === b.getMonth() && now.getDate() < b.getDate())) age--;
    return age >= 0 && age < 120 ? String(age) : '';
}

/**
 * The legacy "Student Enrollment" form, used for new enrollments and edits.
 */
export function EnrollmentForm({ mode, schoolYear, options, initial, action, cancelHref, asRevision = false, section = null }: Props) {
    const start = useMemo(() => ({ ...emptyLearner, ...initial }), [initial]);
    const form = useForm<LearnerForm>(start);
    const { data, setData, errors, processing } = form;
    const [lookup, setLookup] = useState<LookupResult | null>(null);
    const [lookupState, setLookupState] = useState<'idle' | 'checking' | 'error'>('idle');
    // After a save the grade and strand stay for the next learner, since
    // registrars usually encode a batch of the same grade.
    const [kept, setKept] = useState(false);
    const formRef = useRef<HTMLFormElement>(null);
    const lrnRef = useRef<HTMLInputElement>(null);
    const [confirmSection, setConfirmSection] = useState(false);
    const [confirmReset, setConfirmReset] = useState(false);

    // Server-only error (not a form field): a learner with the same name and birthdate exists.
    const duplicateWarning = (errors as Record<string, string | undefined>).possible_duplicate;
    const grade = Number(data.grade_level);
    const isSeniorHigh = grade >= 11;

    // Check the LRN as soon as all 12 digits are typed.
    useEffect(() => {
        if (mode !== 'create' || !/^\d{12}$/.test(data.lrn)) {
            setLookup(null);
            setLookupState('idle');
            return;
        }

        const controller = new AbortController();
        setLookupState('checking');
        fetch(route('students.lookup', { lrn: data.lrn }), { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then((r) => (r.ok ? r.json() : Promise.reject(r)))
            .then((result: LookupResult) => {
                setLookup(result);
                setLookupState('idle');
            })
            .catch((e) => {
                if (e?.name !== 'AbortError') setLookupState('error');
            });

        return () => controller.abort();
    }, [data.lrn, mode]);

    // After a failed save, bring the first problem into view and put the
    // cursor in it, so it can be fixed without reaching for the mouse.
    useEffect(() => {
        if (Object.keys(errors).length === 0) return;
        const first = formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"], [data-error]');
        const control = first?.matches('input, select, textarea')
            ? first
            : first?.querySelector<HTMLElement>('input:not([disabled]), select:not([disabled]), textarea');
        control?.focus({ preventScroll: true });
        first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, [errors]);

    const leavesSection =
        mode === 'edit' &&
        Boolean(section) &&
        (data.grade_level !== start.grade_level || (isSeniorHigh ? data.strand_id : '') !== (start.strand_id ?? ''));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (leavesSection) {
            setConfirmSection(true);
            return;
        }
        save();
    };

    const resetForm = () => {
        form.reset();
        form.clearErrors();
        setLookup(null);
        setConfirmReset(false);
    };

    const save = () => {
        setConfirmSection(false);
        form.transform((d) => ({ ...d, strand_id: isSeniorHigh ? d.strand_id : '' }));
        form.submit(action.method, action.url, {
            preserveScroll: true,
            onSuccess: () => {
                if (mode === 'create') {
                    // Set the next learner's values directly: reset() would still
                    // use the old defaults until the next render.
                    const next = { ...emptyLearner, grade_level: data.grade_level, strand_id: data.strand_id };
                    form.setDefaults(next);
                    form.setData(next);
                    form.clearErrors();
                    setLookup(null);
                    setKept(Boolean(data.grade_level));
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    // Ready for the next learner's LRN.
                    lrnRef.current?.focus({ preventScroll: true });
                }
            },
        });
    };

    const useSavedDetails = () => {
        if (!lookup?.student) return;
        setData((d) => ({ ...d, ...toFormValues(lookup.student!), learner_type: d.learner_type || 'old_student' }));
    };

    const text = (name: keyof LearnerForm) => ({
        value: data[name] as string,
        onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => setData(name, e.target.value as never),
    });

    const strandsByTrack = (track: 'academic' | 'tvl') => options.strands.filter((s) => s.track === track);
    const learnerChoice = learnerTypeChoices.findIndex((c) => c.type === data.learner_type && c.irregular === data.is_irregular);

    return (
        <form ref={formRef} onSubmit={submit} noValidate>
            <div className="panel space-y-8 p-4 sm:p-6">
                {/* Enrollment details */}
                <section className="space-y-4">
                    <h3 className="section-title">Enrollment Details</h3>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <Field label="School Year">{(p) => <TextInput {...p} value={schoolYear} disabled readOnly />}</Field>
                        <Field
                            label="Grade Level to Enroll"
                            required
                            error={errors.grade_level}
                            hint={kept && data.grade_level ? 'Kept from the last learner' : undefined}
                        >
                            {(p) => (
                                <SelectInput
                                    {...p}
                                    value={data.grade_level}
                                    onChange={(e) => {
                                        setKept(false);
                                        setData('grade_level', e.target.value);
                                    }}
                                >
                                    <option value="">Select Grade Level</option>
                                    {options.grades.map((g) => (
                                        <option key={g.value} value={g.value}>
                                            {g.label}
                                        </option>
                                    ))}
                                </SelectInput>
                            )}
                        </Field>
                        <Field label="Learner Reference No. (LRN)" required error={errors.lrn} hint={lrnHint(data.lrn)}>
                            {(p) => (
                                <TextInput
                                    {...p}
                                    ref={lrnRef}
                                    {...text('lrn')}
                                    inputMode="numeric"
                                    maxLength={14}
                                    placeholder="Enter 12-digit LRN"
                                    autoComplete="off"
                                />
                            )}
                        </Field>
                    </div>

                    {mode === 'create' && <LookupBanner lrn={data.lrn} lookup={lookup} state={lookupState} onUse={useSavedDetails} />}
                </section>

                {/* Type of learner */}
                <section className="space-y-4" data-error={errors.learner_type ? true : undefined}>
                    <h3 className="section-title">
                        Type of Learner <span className="text-red-500">*</span>
                    </h3>
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                        {(['REGULAR', 'IRREGULAR'] as const).map((group) => (
                            <fieldset key={group}>
                                <legend className="field-label">{group}</legend>
                                <div className="flex flex-wrap gap-x-6 gap-y-2">
                                    {learnerTypeChoices.map((c, i) =>
                                        c.group !== group ? null : (
                                            <label key={i} className="flex items-center gap-2 text-base text-slate-700">
                                                <input
                                                    type="radio"
                                                    className="radio"
                                                    name="learner_type_choice"
                                                    checked={learnerChoice === i}
                                                    onChange={() => setData((d) => ({ ...d, learner_type: c.type, is_irregular: c.irregular }))}
                                                />
                                                {c.label}
                                            </label>
                                        ),
                                    )}
                                </div>
                            </fieldset>
                        ))}
                    </div>
                    <InputError message={errors.learner_type} />
                </section>

                {/* Track and strand */}
                {isSeniorHigh && (
                    <section className="space-y-4" data-error={errors.strand_id ? true : undefined}>
                        <h3 className="section-title">
                            Track (For Grade 11 &amp; 12) <span className="text-red-500">*</span>
                        </h3>
                        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                            {(['academic', 'tvl'] as const).map((track) => (
                                <fieldset key={track}>
                                    <legend className="field-label">{track === 'academic' ? 'ACADEMIC' : 'TVL'}</legend>
                                    <div className="flex flex-col gap-2">
                                        {strandsByTrack(track).map((s) => (
                                            <label key={s.id} className="flex items-start gap-2 text-base text-slate-700">
                                                <input
                                                    type="radio"
                                                    className="radio mt-1"
                                                    name="strand_id"
                                                    checked={data.strand_id === String(s.id)}
                                                    onChange={() => setData('strand_id', String(s.id))}
                                                />
                                                <span>
                                                    <span className="font-medium">{s.code}</span>
                                                    <span className="block text-sm text-slate-500">{s.name}</span>
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>
                            ))}
                        </div>
                        <InputError message={errors.strand_id} />
                    </section>
                )}

                {/* Learner's information */}
                <section className="space-y-4">
                    <h3 className="section-title">Learner&apos;s Information</h3>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4">
                        <Field label="Last Name" required error={errors.last_name}>
                            {(p) => <TextInput {...p} {...text('last_name')} placeholder="e.g., Dela Cruz" autoComplete="off" />}
                        </Field>
                        <Field label="Given Name" required error={errors.first_name}>
                            {(p) => <TextInput {...p} {...text('first_name')} placeholder="e.g., Juan" autoComplete="off" />}
                        </Field>
                        <Field label="Middle Name" error={errors.middle_name}>
                            {(p) => <TextInput {...p} {...text('middle_name')} placeholder="e.g., Santos" autoComplete="off" />}
                        </Field>
                        <Field label="Suffix" error={errors.extension_name}>
                            {(p) => <TextInput {...p} {...text('extension_name')} placeholder="e.g., Jr." autoComplete="off" />}
                        </Field>
                        <Field label="Birthdate" required error={errors.birthdate} hint={birthdateHint(data.birthdate)}>
                            {(p) => <BirthdateInput {...p} value={data.birthdate} onChange={(v) => setData('birthdate', v)} />}
                        </Field>
                        <Field label="Age">
                            {(p) => <TextInput {...p} value={ageFrom(data.birthdate)} readOnly disabled placeholder="Computed" />}
                        </Field>
                        <Field label="Sex" required error={errors.sex}>
                            {(p) => (
                                <SelectInput {...p} {...text('sex')}>
                                    <option value="">Select</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </SelectInput>
                            )}
                        </Field>
                        <Field label="Religion" error={errors.religion}>
                            {(p) => <TextInput {...p} {...text('religion')} placeholder="e.g., Roman Catholic" />}
                        </Field>
                        <Field label="Mother Tongue" error={errors.mother_tongue}>
                            {(p) => <TextInput {...p} {...text('mother_tongue')} placeholder="e.g., Tagalog" />}
                        </Field>
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <YesNo
                            label="Member of IP Community?"
                            name="is_indigenous"
                            value={data.is_indigenous}
                            onChange={(v) => setData('is_indigenous', v)}
                        />
                        <Field label="If yes, specify:" error={errors.indigenous_group}>
                            {(p) => (
                                <TextInput {...p} {...text('indigenous_group')} disabled={!data.is_indigenous} placeholder="Specify IP community" />
                            )}
                        </Field>
                        <YesNo label="With Disability?" name="is_pwd" value={data.is_pwd} onChange={(v) => setData('is_pwd', v)} />
                        <Field label="If yes, specify disability:" error={errors.disability}>
                            {(p) => <TextInput {...p} {...text('disability')} disabled={!data.is_pwd} placeholder="Specify disability" />}
                        </Field>
                        <YesNo
                            label="4Ps Beneficiary?"
                            name="is_4ps_beneficiary"
                            value={data.is_4ps_beneficiary}
                            onChange={(v) => setData('is_4ps_beneficiary', v)}
                        />
                        <div />
                        <Field label="Weight (kg)" error={errors.weight_kg}>
                            {(p) => <TextInput {...p} {...text('weight_kg')} inputMode="decimal" placeholder="Weight in kg" />}
                        </Field>
                        <Field label="Height (m)" error={errors.height_m} hint="In metres, for example 1.58">
                            {(p) => <TextInput {...p} {...text('height_m')} inputMode="decimal" placeholder="Height in meters" />}
                        </Field>
                    </div>

                    <div className="space-y-2">
                        <p className="field-label pb-0">Current Address</p>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            <Field small label="House No." error={errors.house_number}>
                                {(p) => <TextInput {...p} {...text('house_number')} placeholder="e.g., 123" />}
                            </Field>
                            <Field small label="Sitio/Street" error={errors.street}>
                                {(p) => <TextInput {...p} {...text('street')} placeholder="e.g., Sampaguita St." />}
                            </Field>
                            <Field small label="Barangay" required error={errors.barangay}>
                                {(p) => <TextInput {...p} {...text('barangay')} placeholder="e.g., Don Jose" />}
                            </Field>
                            <Field small label="Municipality/City" required error={errors.municipality}>
                                {(p) => <TextInput {...p} {...text('municipality')} placeholder="e.g., Santa Rosa" list="municipalities" />}
                            </Field>
                            <Field small label="Province" required error={errors.province}>
                                {(p) => <TextInput {...p} {...text('province')} placeholder="e.g., Laguna" list="provinces" />}
                            </Field>
                        </div>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field small label="Zip Code" error={errors.zip_code}>
                                {(p) => <TextInput {...p} {...text('zip_code')} inputMode="numeric" maxLength={4} placeholder="e.g., 4026" />}
                            </Field>
                            <Field small label="Country" error={errors.country}>
                                {(p) => <TextInput {...p} {...text('country')} />}
                            </Field>
                        </div>
                        {/* Common values, so the same town is not spelled five ways. */}
                        <datalist id="municipalities">
                            {['SANTA ROSA', 'BIÑAN', 'CABUYAO', 'SILANG', 'CALAMBA', 'SAN PEDRO', 'CARMONA', 'GENERAL TRIAS'].map((m) => (
                                <option key={m} value={m} />
                            ))}
                        </datalist>
                        <datalist id="provinces">
                            {['LAGUNA', 'CAVITE', 'BATANGAS', 'RIZAL', 'QUEZON', 'METRO MANILA'].map((m) => (
                                <option key={m} value={m} />
                            ))}
                        </datalist>
                    </div>
                </section>

                {/* Parents and guardian */}
                <section className="space-y-4">
                    <h3 className="section-title">Parent/Guardian Information</h3>
                    {(
                        [
                            ['father', "Father's Name"],
                            ['mother', "Mother's Maiden Name"],
                            ['guardian', "Legal Guardian's Name (if not parent)"],
                        ] as const
                    ).map(([who, title]) => (
                        <div key={who} className="space-y-2">
                            <p className="field-label pb-0">{title}</p>
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <Field small label="Last Name" srPrefix={owner[who]} error={errors[`${who}_last_name`]}>
                                    {(p) => <TextInput {...p} {...text(`${who}_last_name`)} />}
                                </Field>
                                <Field small label="Given Name" srPrefix={owner[who]} error={errors[`${who}_first_name`]}>
                                    {(p) => <TextInput {...p} {...text(`${who}_first_name`)} />}
                                </Field>
                                <Field small label="Middle Name" srPrefix={owner[who]} error={errors[`${who}_middle_name`]}>
                                    {(p) => <TextInput {...p} {...text(`${who}_middle_name`)} />}
                                </Field>
                            </div>
                        </div>
                    ))}
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <Field label="Guardian's Relationship" error={errors.guardian_relationship}>
                            {(p) => <TextInput {...p} {...text('guardian_relationship')} placeholder="e.g., Grandmother" />}
                        </Field>
                        <Field label="Contact Number" required error={errors.contact_number}>
                            {(p) => <TextInput {...p} {...text('contact_number')} inputMode="tel" placeholder="09XXXXXXXXX" />}
                        </Field>
                    </div>
                </section>

                <section className="space-y-4">
                    <h3 className="section-title">Remarks</h3>
                    <Field label="Notes for this enrollment" error={errors.remarks}>
                        {(p) => <textarea {...p} {...text('remarks')} rows={3} className="field-input" placeholder="Optional" />}
                    </Field>
                </section>

                {asRevision && (
                    <section className="space-y-4 rounded-lg border border-blue-200 bg-blue-50 p-4">
                        <p className="text-sm text-blue-900">
                            Your changes are sent to the Registrar or ICT Coordinator for approval. Nothing changes until they approve.
                        </p>
                        <Field label="Reason for the change" required error={(errors as Record<string, string | undefined>).justification}>
                            {(p) => (
                                <textarea
                                    {...p}
                                    rows={2}
                                    className="field-input bg-white"
                                    value={data.justification}
                                    onChange={(e) => setData('justification', e.target.value)}
                                    placeholder="e.g., Birth date corrected based on the PSA certificate"
                                />
                            )}
                        </Field>
                    </section>
                )}

                {mode === 'create' && (
                    <section
                        className="rounded-lg border border-green-200 bg-green-50 p-4"
                        data-error={errors.privacy_notice_given ? true : undefined}
                    >
                        <h3 className="flex items-center gap-2 text-sm font-semibold text-green-900">
                            <Icon name="shield" className="text-green-700" /> Data Privacy
                        </h3>
                        <p className="mt-1 text-sm text-green-900">
                            Before encoding, give the parent, guardian or learner (if 18 or older) the school's{' '}
                            <a href={route('privacy.notice')} target="_blank" rel="noreferrer" className="font-medium underline">
                                privacy notice
                            </a>
                            . It explains why this information is collected and their rights under the Data Privacy Act (RA 10173).
                        </p>
                        <label className="mt-3 flex items-start gap-2 text-sm text-green-950">
                            <input
                                type="checkbox"
                                className="checkbox mt-0.5"
                                checked={data.privacy_notice_given}
                                onChange={(e) => setData('privacy_notice_given', e.target.checked)}
                            />
                            The parent, guardian or adult learner was given the privacy notice.
                        </label>
                        {errors.privacy_notice_given && <p className="mt-2 text-sm text-red-600">{errors.privacy_notice_given}</p>}
                    </section>
                )}

                {duplicateWarning && (
                    <div className="rounded-lg border border-amber-300 bg-amber-50 p-4" data-error>
                        <p className="flex items-start gap-2 text-sm font-medium text-amber-900">
                            <Icon name="warning" className="mt-px text-amber-600" />
                            {duplicateWarning}
                        </p>
                        <label className="mt-3 flex items-center gap-2 text-sm text-amber-900">
                            <input
                                type="checkbox"
                                className="checkbox"
                                checked={data.confirm_not_duplicate}
                                onChange={(e) => setData('confirm_not_duplicate', e.target.checked)}
                            />
                            This is a different learner. Save anyway.
                        </label>
                    </div>
                )}
            </div>

            {/* Sticky action bar, as on the legacy form. */}
            <div className="sticky bottom-0 z-20 -mx-4 mt-6 flex justify-end gap-3 border-t border-slate-200 bg-white/95 px-4 py-4 backdrop-blur sm:-mx-6 sm:px-6 md:-mx-10 md:px-10">
                {cancelHref ? (
                    <Link href={cancelHref} className="btn-secondary">
                        Cancel
                    </Link>
                ) : (
                    <button type="button" className="btn-secondary" onClick={() => (form.isDirty ? setConfirmReset(true) : resetForm())}>
                        Reset Form
                    </button>
                )}
                <button type="submit" className={cn('btn-primary')} disabled={processing}>
                    {processing ? 'Saving…' : mode === 'create' ? 'Submit Enrollment' : asRevision ? 'Send for Approval' : 'Save Changes'}
                </button>
            </div>
            <ConfirmDialog
                open={confirmSection}
                onClose={() => setConfirmSection(false)}
                onConfirm={save}
                processing={processing}
                title={`Take the learner out of ${section ?? 'their section'}?`}
                message={
                    asRevision
                        ? `A new grade or strand does not fit ${section}. If this change is approved, the learner leaves the section and will need to be placed again.`
                        : `A new grade or strand does not fit ${section}. The learner will leave the section and will need to be placed again in Assign to Sections.`
                }
                confirmLabel={asRevision ? 'Yes, send for approval' : 'Yes, save changes'}
                icon="person_remove"
            />
            <ConfirmDialog
                open={confirmReset}
                onClose={() => setConfirmReset(false)}
                onConfirm={resetForm}
                title="Clear everything typed so far?"
                message="All the details on this form will be erased. Nothing has been saved yet."
                confirmLabel="Yes, clear the form"
                icon="delete"
            />
        </form>
    );
}

const owner = { father: "Father's", mother: "Mother's", guardian: "Guardian's" } as const;

function lrnHint(lrn: string): string {
    const n = lrn.replace(/\D/g, '').length;
    return n === 0 || n === 12 ? '12 digits' : `${n} of 12 digits`;
}

function birthdateHint(value: string): string {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        const age = ageFrom(value);
        return `${describeDate(value)}${age ? ` · ${age} years old` : ''}`;
    }
    return value ? 'Not a date yet. Type it as MM/DD/YYYY, for example 04/18/2010.' : 'MM/DD/YYYY';
}

/**
 * A plain text box for the birth date, faster than a date picker when
 * copying from a PSA certificate. Typing 04182010 shows as 04/18/2010; a
 * pasted 2010-04-18 or "April 18, 2010" also works. The form keeps the date
 * as YYYY-MM-DD, or the raw text if it isn't a date yet, so the server can
 * say what's wrong.
 */
function BirthdateInput({
    value,
    onChange,
    ...props
}: Omit<React.InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange'> & { value: string; onChange: (value: string) => void }) {
    const [text, setText] = useState(() => toTypedDate(value));

    // Follow changes made elsewhere: a reset, or details filled in from an existing record.
    useEffect(() => {
        if (value !== (parseTypedDate(text) ?? text)) setText(toTypedDate(value));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    return (
        <TextInput
            {...props}
            value={text}
            placeholder="MM/DD/YYYY"
            autoComplete="off"
            maxLength={20}
            onChange={(e) => {
                const typed = formatWhileTyping(e.target.value);
                setText(typed);
                onChange(parseTypedDate(typed) ?? typed);
            }}
        />
    );
}

function LookupBanner({
    lrn,
    lookup,
    state,
    onUse,
}: {
    lrn: string;
    lookup: LookupResult | null;
    state: 'idle' | 'checking' | 'error';
    onUse: () => void;
}) {
    if (!/^\d{12}$/.test(lrn)) return null;

    if (state === 'checking') {
        return <p className="text-sm text-slate-500">Checking LRN…</p>;
    }

    if (state === 'error') {
        return <p className="text-sm text-slate-500">Could not check the LRN right now. It will be checked again when you submit.</p>;
    }

    if (lookup?.enrollment) {
        return (
            <div className="flex flex-wrap items-center gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                <Icon name="error" className="text-red-600" />
                <span className="flex-1">
                    <strong>{lookup.student?.full_name}</strong> is already encoded this school year: {lookup.enrollment.summary}.
                </span>
                <Link href={route('enrollments.show', lookup.enrollment.id)} className="font-semibold underline">
                    View record
                </Link>
            </div>
        );
    }

    if (lookup?.student) {
        return (
            <div className="flex flex-wrap items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                <Icon name="info" className="text-blue-600" />
                <span className="flex-1">
                    This LRN belongs to <strong>{lookup.student.full_name}</strong>, who is already in the system but not yet enrolled this school
                    year.
                </span>
                <button type="button" onClick={onUse} className="btn-outline py-1.5">
                    Use their saved details
                </button>
            </div>
        );
    }

    return (
        <p className="flex items-center gap-1.5 text-sm text-emerald-700">
            <Icon name="check_circle" /> New learner. No record with this LRN yet.
        </p>
    );
}
