import { Icon } from '@/components/icon';
import { type HistoryEntry } from '@/pages/enrollments/show';
import { Head } from '@inertiajs/react';
import { type PrivacyDetails } from './types';

interface EnrollmentRow {
    school_year: string;
    grade: number;
    strand: string | null;
    section: string | null;
    learner_type: string;
    status: string;
    encoded: string;
    privacy_notice: string;
    documents: string[];
    weight_kg: number | null;
    height_m: number | null;
}

interface Props {
    privacy: PrivacyDetails;
    generatedAt: string;
    generatedBy: string;
    learner: { name: string; lrn: string | null; fields: { label: string; value: string | null }[] };
    enrollments: EnrollmentRow[];
    changes: HistoryEntry[];
    access: HistoryEntry[];
}

const when = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' });

/**
 * Everything the system holds about one learner, for a parent or adult
 * learner who asks for a copy (the right of access under RA 10173).
 */
export default function LearnerDataRecord({ privacy, generatedAt, generatedBy, learner, enrollments, changes, access }: Props) {
    return (
        <div className="min-h-dvh bg-slate-50 print:bg-white">
            <Head title={`DJIHS Learner Data Record - ${learner.name}`} />
            <article className="mx-auto max-w-4xl bg-white px-5 py-8 text-sm text-slate-800 sm:px-10 print:p-0">
                <header className="border-primary mb-6 flex items-center gap-4 border-b-2 pb-4">
                    <img src="/images/djihs-logo-large.png" alt="" className="size-14 shrink-0" />
                    <div>
                        <p className="text-primary text-xs font-semibold tracking-wide uppercase">{privacy.school_name}</p>
                        <h1 className="text-xl font-bold text-slate-900 sm:text-2xl">Learner Data Record</h1>
                        <p className="text-slate-600">
                            {learner.name}
                            {learner.lrn && <span className="font-mono"> · LRN {learner.lrn}</span>}
                        </p>
                    </div>
                </header>

                <p className="mb-6 text-slate-600">
                    All personal information the DJIHS Enrollment System holds about this learner, provided under the right of access in the Data
                    Privacy Act of 2012 (RA 10173). Prepared by {generatedBy} on {when.format(new Date(generatedAt))}. If anything is wrong, ask the
                    registrar to correct it.
                </p>

                <Heading>Personal information</Heading>
                <dl className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                    {learner.fields.map((f) => (
                        <div key={f.label} className="flex justify-between gap-4 border-b border-slate-100 py-1.5">
                            <dt className="text-slate-500">{f.label}</dt>
                            <dd className="text-right font-medium text-slate-900">{f.value ?? '—'}</dd>
                        </div>
                    ))}
                </dl>

                <Heading>Enrollments</Heading>
                <div className="space-y-3">
                    {enrollments.map((e) => (
                        <div key={e.school_year} className="break-inside-avoid rounded-lg border border-slate-200 p-3">
                            <p className="font-semibold text-slate-900">
                                School year {e.school_year} · Grade {e.grade}
                                {e.strand && ` · ${e.strand}`}
                                {e.section && ` · ${e.section}`}
                            </p>
                            <p className="mt-1 text-slate-600">
                                {e.learner_type} · {e.status} · Encoded {e.encoded}
                            </p>
                            <p className="text-slate-600">
                                Weight: {e.weight_kg ?? '—'} kg · Height: {e.height_m ?? '—'} m
                            </p>
                            <p className="text-slate-600">Documents handed in: {e.documents.length ? e.documents.join(', ') : 'none recorded'}</p>
                            <p className="text-slate-600">Privacy notice given: {e.privacy_notice}</p>
                        </div>
                    ))}
                </div>

                <Heading>Changes to the record</Heading>
                <Log entries={changes} empty="No changes recorded." />

                <Heading>Who viewed or downloaded the record</Heading>
                <Log entries={access} empty="No views or downloads recorded." />

                <button type="button" className="btn-primary mt-8 print:hidden" onClick={() => window.print()}>
                    <Icon name="print" /> Print or Save as PDF
                </button>
            </article>
        </div>
    );
}

function Heading({ children }: { children: React.ReactNode }) {
    return <h2 className="mt-8 mb-3 border-b border-slate-300 pb-1 text-base font-semibold text-slate-900">{children}</h2>;
}

function Log({ entries, empty }: { entries: HistoryEntry[]; empty: string }) {
    if (entries.length === 0) {
        return <p className="text-slate-500">{empty}</p>;
    }

    return (
        <ul className="space-y-1.5">
            {entries.map((h) => (
                <li key={h.id} className="break-inside-avoid">
                    <span className="text-slate-500">{when.format(new Date(h.at))}</span> · <span className="font-medium">{h.user}</span>{' '}
                    {h.detail
                        ? `${h.event === 'viewed' ? 'viewed' : 'downloaded'}: ${h.detail}`
                        : h.event === 'created'
                          ? `created the ${h.subject_type.toLowerCase()}`
                          : h.changes.map((c) => `${c.field}: ${c.old ?? 'blank'} → ${c.new ?? 'blank'}`).join('; ')}
                </li>
            ))}
        </ul>
    );
}
