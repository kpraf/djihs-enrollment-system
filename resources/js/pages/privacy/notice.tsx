import { Icon } from '@/components/icon';
import { Head } from '@inertiajs/react';
import { type PrivacyDetails } from './types';

/**
 * The privacy notice given to parents, guardians and adult learners before
 * their information is encoded (Data Privacy Act of 2012, RA 10173). Public
 * so it can be opened on a phone or printed and handed out.
 */
export default function PrivacyNotice({ privacy }: { privacy: PrivacyDetails }) {
    const school = privacy.school_name;

    return (
        <div className="min-h-dvh bg-slate-50 print:bg-white">
            <Head title="Privacy Notice" />
            <article className="mx-auto max-w-3xl bg-white px-5 py-8 text-slate-800 sm:px-10 sm:py-10 print:p-0">
                <header className="border-primary mb-6 flex items-center gap-4 border-b-2 pb-4">
                    <img src="/images/djihs-logo-large.png" alt="" className="size-14 shrink-0 sm:size-16" />
                    <div>
                        <p className="text-primary text-xs font-semibold tracking-wide uppercase sm:text-sm">{school}</p>
                        <h1 className="text-xl font-bold text-slate-900 sm:text-2xl">Privacy Notice for Learners and Parents</h1>
                        {privacy.school_address && <p className="text-sm text-slate-500">{privacy.school_address}</p>}
                    </div>
                </header>

                <div className="space-y-6 leading-relaxed">
                    <p>
                        {school} collects learners' personal information to enroll them and keep their school records. This notice explains what we
                        collect, why, who can see it, and your rights under the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>.
                    </p>

                    <Section title="What we collect">
                        <ul className="list-disc space-y-1 pl-5">
                            <li>Learner Reference Number (LRN), name, birth date, sex, religion and mother tongue</li>
                            <li>Home address and a contact number</li>
                            <li>Names of the father, mother and legal guardian</li>
                            <li>Whether the learner belongs to an Indigenous Peoples community, is a 4Ps beneficiary, or has a disability</li>
                            <li>Grade level, strand, section, type of learner and enrollment status</li>
                            <li>Weight and height, for the nutritional status report (School Form 8)</li>
                            <li>Which enrollment documents were handed in, such as the birth certificate and report card</li>
                        </ul>
                        <p className="mt-2">
                            Some of these, such as religion, disability and membership in an Indigenous community, are{' '}
                            <em>sensitive personal information</em> under the law and are protected more strictly.
                        </p>
                    </Section>

                    <Section title="Why we collect it">
                        <ul className="list-disc space-y-1 pl-5">
                            <li>To enroll the learner and place them in a grade, strand and section</li>
                            <li>To prepare the school forms and reports that the Department of Education (DepEd) requires</li>
                            <li>
                                To plan support for learners, such as programs for Indigenous Peoples, 4Ps beneficiaries and learners with disability
                            </li>
                            <li>To contact the parent or guardian about the learner</li>
                        </ul>
                        <p className="mt-2">We collect this because enrolling learners and reporting to DepEd are part of the school's duties.</p>
                    </Section>

                    <Section title="Who can see it">
                        <p>
                            Only school staff whose work needs it, such as the registrar, the learner's adviser and teachers, each seeing only what
                            their role requires. Records are reported to DepEd as required. We do not sell learners' information or share it for
                            advertising.
                        </p>
                    </Section>

                    <Section title="How we protect it">
                        <ul className="list-disc space-y-1 pl-5">
                            <li>Every staff member has their own account and password.</li>
                            <li>Each person can open only the parts of the system their work needs.</li>
                            <li>
                                Every change, and every time someone views or downloads a learner's record, is recorded with their name and the time.
                            </li>
                            <li>Staff accept a confidentiality agreement before they can use the system.</li>
                        </ul>
                    </Section>

                    <Section title="How long we keep it">
                        <p>Records are kept {privacy.retention}, then disposed of securely.</p>
                    </Section>

                    <Section title="Your rights">
                        <p>As a learner, or as the parent or guardian of a learner under 18, you have the right to:</p>
                        <ul className="mt-2 list-disc space-y-1 pl-5">
                            <li>
                                <strong>Be informed</strong> of how the information is used, as in this notice
                            </li>
                            <li>
                                <strong>Access</strong> it and get a copy of the learner's record
                            </li>
                            <li>
                                <strong>Have it corrected</strong> if it is wrong or incomplete
                            </li>
                            <li>
                                <strong>Object</strong> to its use, and ask for it to be <strong>erased or blocked</strong>, where the law allows.
                                Records that DepEd requires schools to keep cannot be erased.
                            </li>
                            <li>
                                <strong>Data portability</strong>: get it in a form you can take elsewhere
                            </li>
                            <li>
                                <strong>Be compensated</strong> for damage caused by misuse
                            </li>
                            <li>
                                <strong>File a complaint</strong> with the National Privacy Commission (privacy.gov.ph)
                            </li>
                        </ul>
                    </Section>

                    <Section title="Questions or requests">
                        {privacy.dpo_name || privacy.dpo_email || privacy.dpo_phone ? (
                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                <p>Contact the school's Data Protection Officer:</p>
                                {privacy.dpo_name && <p className="mt-1 font-semibold text-slate-900">{privacy.dpo_name}</p>}
                                {privacy.dpo_email && <p>{privacy.dpo_email}</p>}
                                {privacy.dpo_phone && <p>{privacy.dpo_phone}</p>}
                            </div>
                        ) : (
                            <p>Ask at the school's registrar's office for the Data Protection Officer.</p>
                        )}
                    </Section>

                    <section className="hidden border-t-2 border-slate-300 pt-6 print:block">
                        <h2 className="font-semibold text-slate-900">Acknowledgment</h2>
                        <p className="mt-2 text-sm">I have received and understood this privacy notice.</p>
                        <div className="mt-8 grid grid-cols-2 gap-x-10 gap-y-10 text-sm">
                            <p className="border-t border-slate-400 pt-1">Learner's name</p>
                            <p className="border-t border-slate-400 pt-1">Parent or guardian's name and signature</p>
                            <p className="border-t border-slate-400 pt-1">Grade level</p>
                            <p className="border-t border-slate-400 pt-1">Date</p>
                        </div>
                    </section>
                </div>

                <button type="button" className="btn-primary mt-8 print:hidden" onClick={() => window.print()}>
                    <Icon name="print" /> Print or Save as PDF
                </button>
            </article>
        </div>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section>
            <h2 className="mb-2 text-lg font-semibold text-slate-900">{title}</h2>
            {children}
        </section>
    );
}
