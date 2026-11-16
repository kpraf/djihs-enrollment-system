import { Icon } from '@/components/icon';
import { BusyLabel } from '@/components/spinner';
import GuestLayout from '@/layouts/guest-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { type PrivacyDetails } from './types';

/**
 * Staff accept this once, before they can see any learner's data. Asked of
 * personnel who handle personal information under the Data Privacy Act.
 */
export default function ConfidentialityAgreement({ privacy }: { privacy: PrivacyDetails }) {
    const { data, setData, post, processing, errors } = useForm({ agree: false as boolean });

    return (
        <GuestLayout>
            <Head title="Confidentiality Agreement" />
            <div className="mb-5 flex items-center gap-3">
                <div className="flex size-11 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-700">
                    <Icon name="shield" />
                </div>
                <div>
                    <h1 className="text-2xl font-bold text-slate-900">Confidentiality Agreement</h1>
                    <p className="text-sm text-slate-500">Data Privacy Act of 2012 (RA 10173)</p>
                </div>
            </div>

            <p className="text-sm text-slate-700">
                This system holds the personal information of {privacy.school_name}'s learners and their families. Before you continue, please read
                and accept the following:
            </p>
            <ol className="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-700">
                <li>I will use learners' information only for my work at the school.</li>
                <li>
                    I will not share, copy, photograph or post learners' information outside the school's official work, including on social media and
                    in group chats.
                </li>
                <li>
                    I will keep downloaded files such as class lists, SF1 and backups on school devices, and delete them when they are no longer
                    needed.
                </li>
                <li>I will keep my password to myself and sign out when I leave a shared computer.</li>
                <li>
                    I will report a lost file, a lost device or a suspected leak to the Data Protection Officer right away, because serious breaches
                    must be reported to the National Privacy Commission quickly.
                </li>
                <li>I understand that every time I view or download a learner's record, it is recorded with my name.</li>
                <li>I will keep this information confidential even after I leave the school.</li>
            </ol>
            <p className="mt-3 text-sm">
                <a href={route('privacy.notice')} target="_blank" rel="noreferrer" className="text-primary font-medium underline">
                    Read the privacy notice given to parents
                </a>
            </p>

            <form
                className="mt-6 space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    post(route('privacy.agree'));
                }}
            >
                <label className="flex items-start gap-2 text-sm font-medium text-slate-900">
                    <input type="checkbox" className="checkbox mt-0.5" checked={data.agree} onChange={(e) => setData('agree', e.target.checked)} />I
                    have read and agree to keep learners' information confidential.
                </label>
                {errors.agree && <p className="text-sm text-red-600">{errors.agree}</p>}
                <button type="submit" disabled={processing} className="btn-primary w-full py-3">
                    <BusyLabel busy={processing} busyText="Saving…">
                        Accept and Continue <Icon name="arrow_forward" />
                    </BusyLabel>
                </button>
                <Link href={route('logout')} method="post" as="button" className="w-full text-center text-sm text-slate-500 hover:text-slate-700">
                    Not now, sign out
                </Link>
            </form>
        </GuestLayout>
    );
}
