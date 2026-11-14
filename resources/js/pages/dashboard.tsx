import { CapacityBar } from '@/components/capacity-bar';
import { Icon } from '@/components/icon';
import { type IconName } from '@/components/icons';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type Ability, type AuthedData, type Option } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface Tally {
    male: number;
    female: number;
    total: number;
    learner_types: Record<string, number>;
    indigenous: number;
    four_ps: number;
}

interface GradeSummary {
    grade_level: number;
    label: string;
    strands: (Tally & { code: string; name: string; track: string })[];
    tracks: { academic: Tally; tvl: Tally };
    total: Tally;
}

interface Monitoring {
    grades: GradeSummary[];
    total: Tally;
    cancelled: number;
    grade_10_completers: number | null;
    grade_11_retention: number | null;
    learner_types: Option[];
}

interface DailyRow {
    date: string | null;
    grades: Record<string, { male: number; female: number; total: number }>;
    male: number;
    female: number;
    total: number;
}

interface Props {
    schoolYears: { id: number; name: string; is_active: boolean }[];
    selectedYear: { id: number; name: string } | null;
    monitoring: Monitoring | null;
    daily: DailyRow[];
    review: {
        pending: number;
        recent: { id: number; name: string; grade_level: number; strand: string | null; encoded_on: string | null }[];
        revisions: number | null;
        transferees: number;
    } | null;
    mySections: { id: number; label: string; filled: number; capacity: number }[] | null;
    sectionOverview: { sections: number; capacity: number; assigned: number; unassigned: number; without_adviser: number; full: number } | null;
    teaching: {
        classes: number;
        learners: number;
        by_subject: { label: string; classes: number }[];
        by_grade: { label: string; classes: number }[];
    } | null;
    system: {
        users: number;
        active_users: number;
        roles: { label: string; count: number }[];
        activity_today: number;
        recent: { id: number; at: string; user: string; event: string; subject_type: string; subject: string }[];
    } | null;
}

const number = new Intl.NumberFormat('en-PH');
const dateFormat = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric', weekday: 'short' });

const quickActions: { label: string; href: string; icon: IconName; ability: Ability }[] = [
    { label: 'New Enrollment', href: '/enrollments/create', icon: 'person_add', ability: 'encode-enrollments' },
    { label: 'Review Enrollments', href: '/reviews', icon: 'rate_review', ability: 'review-enrollments' },
    { label: 'Search Students', href: '/enrollments', icon: 'search', ability: 'view-enrollments' },
    { label: 'Import Spreadsheet', href: '/imports', icon: 'upload_file', ability: 'import-enrollments' },
];

export default function Dashboard({ schoolYears, selectedYear, monitoring, daily, review, mySections, sectionOverview, teaching, system }: Props) {
    const { auth } = usePage<AuthedData>().props;
    const can = useCan();
    const actions = quickActions.filter((a) => can(a.ability));

    return (
        <AppLayout>
            <Head title="Dashboard" />

            <PageHeader
                title={`Welcome, ${auth.user.first_name}!`}
                description={
                    selectedYear
                        ? `Here's an overview of the enrollment status for school year ${selectedYear.name}.`
                        : 'No school year has been set up yet.'
                }
                actions={
                    schoolYears.length > 0 && (
                        <label className="flex items-center gap-3">
                            <span className="text-sm font-medium whitespace-nowrap text-slate-700">School Year:</span>
                            <select
                                className="field-input-sm h-10 min-w-[11rem] font-medium"
                                value={selectedYear?.id ?? ''}
                                onChange={(e) => router.get('/dashboard', { school_year: e.target.value }, { preserveState: true })}
                            >
                                {schoolYears.map((y) => (
                                    <option key={y.id} value={y.id}>
                                        {y.name}
                                        {y.is_active ? ' (current)' : ''}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )
                }
            />

            {monitoring && (
                <div className="mb-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Total Enrolled"
                        value={number.format(monitoring.total.total)}
                        hint={`${monitoring.total.male} male · ${monitoring.total.female} female`}
                    />
                    {monitoring.grades.slice(0, 2).map((g) => (
                        <StatCard
                            key={g.grade_level}
                            label={g.label}
                            value={number.format(g.total.total)}
                            hint={`Academic ${g.tracks.academic.total} · TVL ${g.tracks.tvl.total}`}
                            tone="indigo"
                        />
                    ))}
                    <StatCard label="Cancelled" value={number.format(monitoring.cancelled)} hint="Not counted as enrolled" tone="orange" />
                </div>
            )}

            <RoleBlocks review={review} mySections={mySections} sectionOverview={sectionOverview} teaching={teaching} system={system} />

            {monitoring && monitoring.grade_11_retention !== null && (
                <div className="panel mb-8 flex flex-wrap items-center gap-4 p-6">
                    <div className="text-primary flex size-12 items-center justify-center rounded-lg bg-green-50">
                        <Icon name="school" />
                    </div>
                    <div>
                        <p className="text-base font-medium text-slate-700">Grade 10 completers who stayed for Grade 11</p>
                        <p className="text-sm text-slate-500">
                            <span className="text-2xl font-bold text-slate-900">{(monitoring.grade_11_retention * 100).toFixed(1)}%</span> of{' '}
                            {monitoring.grade_10_completers} completers enrolled as old students
                        </p>
                    </div>
                </div>
            )}

            {monitoring?.grades.map((grade) => <GradeTable key={grade.grade_level} grade={grade} types={monitoring.learner_types} />)}

            {monitoring && monitoring.grades.length === 0 && (
                <div className="panel mb-8 px-6 py-12 text-center text-slate-500">No learners have been encoded for this school year yet.</div>
            )}

            {daily.length > 0 && <DailyTable rows={daily} grades={monitoring?.grades.map((g) => g.grade_level) ?? []} />}

            {actions.length > 0 && (
                <section>
                    <h2 className="mb-4 text-xl font-bold tracking-tight text-slate-900">Quick Actions</h2>
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        {actions.map((a) => (
                            <Link
                                key={a.href}
                                href={a.href}
                                className="panel hover:border-primary flex flex-col items-center justify-center gap-2 p-6 transition-colors hover:bg-green-50/40"
                            >
                                <Icon name={a.icon} className="text-primary" />
                                <span className="text-base font-bold text-slate-900">{a.label}</span>
                            </Link>
                        ))}
                    </div>
                </section>
            )}
        </AppLayout>
    );
}

const th = 'px-4 py-3 text-right font-medium whitespace-nowrap';
const td = 'px-4 py-3 text-right tabular-nums';

function TallyCells({ tally, types }: { tally: Tally; types: Option[] }) {
    return (
        <>
            <td className={td}>{tally.male}</td>
            <td className={td}>{tally.female}</td>
            <td className={cn(td, 'font-bold text-slate-900')}>{tally.total}</td>
            {types.map((t) => (
                <td key={t.value} className={cn(td, tally.learner_types[t.value] === 0 && 'text-slate-300')}>
                    {tally.learner_types[t.value]}
                </td>
            ))}
            <td className={td}>{tally.indigenous}</td>
            <td className={td}>{tally.four_ps}</td>
        </>
    );
}

function GradeTable({ grade, types }: { grade: GradeSummary; types: Option[] }) {
    return (
        <section className="mb-8">
            <h2 className="mb-4 text-xl font-bold tracking-tight text-slate-900">{grade.label}</h2>
            <div className="panel overflow-hidden">
                <div className="relative overflow-x-auto">
                    <table className="w-full text-left text-sm text-slate-500">
                        <thead className="table-head">
                            <tr>
                                <th className="px-4 py-3 font-medium">Strand</th>
                                <th className={th}>Male</th>
                                <th className={th}>Female</th>
                                <th className={th}>Total</th>
                                {types.map((t) => (
                                    <th key={t.value} className={th}>
                                        {t.label}
                                    </th>
                                ))}
                                <th className={th} title="Indigenous Peoples">
                                    IP
                                </th>
                                <th className={th}>4Ps</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {grade.strands.map((s) => (
                                <tr key={s.code} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 font-medium whitespace-nowrap text-slate-900" title={s.name}>
                                        {s.code}
                                    </td>
                                    <TallyCells tally={s} types={types} />
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="divide-y divide-slate-100 border-t border-slate-200">
                            <tr className="bg-slate-50/60">
                                <td className="px-4 py-3 whitespace-nowrap text-slate-700">Academic track</td>
                                <TallyCells tally={grade.tracks.academic} types={types} />
                            </tr>
                            <tr className="bg-slate-50/60">
                                <td className="px-4 py-3 whitespace-nowrap text-slate-700">TVL track</td>
                                <TallyCells tally={grade.tracks.tvl} types={types} />
                            </tr>
                            <tr className="bg-slate-100 font-semibold text-slate-900">
                                <td className="px-4 py-3">Total</td>
                                <TallyCells tally={grade.total} types={types} />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </section>
    );
}

function DailyTable({ rows, grades }: { rows: DailyRow[]; grades: number[] }) {
    return (
        <section className="mb-8">
            <h2 className="text-xl font-bold tracking-tight text-slate-900">Daily Encoding</h2>
            <p className="mb-4 text-sm text-slate-500">Learners encoded each day, including ones later cancelled.</p>
            <div className="panel overflow-hidden">
                <div className="relative max-h-[28rem] overflow-auto">
                    <table className="w-full text-left text-sm text-slate-500">
                        <thead className="table-head sticky top-0">
                            <tr>
                                <th className="px-4 py-3 font-medium">Date</th>
                                {grades.map((g) => (
                                    <th key={g} className={th}>
                                        Grade {g}
                                    </th>
                                ))}
                                <th className={th}>Male</th>
                                <th className={th}>Female</th>
                                <th className={th}>Total</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((r) => (
                                <tr key={r.date ?? 'none'} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 whitespace-nowrap text-slate-900">
                                        {r.date ? (
                                            dateFormat.format(new Date(r.date + 'T00:00:00'))
                                        ) : (
                                            <span className="text-slate-500 italic">No date recorded</span>
                                        )}
                                    </td>
                                    {grades.map((g) => (
                                        <td key={g} className={td}>
                                            {r.grades[g]?.total ?? 0}
                                        </td>
                                    ))}
                                    <td className={td}>{r.male}</td>
                                    <td className={td}>{r.female}</td>
                                    <td className={cn(td, 'font-bold text-slate-900')}>{r.total}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    );
}

const when = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' });

function Block({ title, action, children }: { title: string; action?: React.ReactNode; children: React.ReactNode }) {
    return (
        <section className="panel p-6">
            <div className="mb-4 flex items-center justify-between gap-3">
                <h2 className="text-xl font-bold tracking-tight text-slate-900">{title}</h2>
                {action}
            </div>
            {children}
        </section>
    );
}

function RoleBlocks({
    review,
    mySections,
    sectionOverview,
    teaching,
    system,
}: Pick<Props, 'review' | 'mySections' | 'sectionOverview' | 'teaching' | 'system'>) {
    if (!review && !mySections && !sectionOverview && !teaching && !system) return null;

    return (
        <div className="mb-8 grid grid-cols-1 gap-6 xl:grid-cols-2">
            {review && (
                <Block
                    title="Pending Enrollments"
                    action={
                        <Link href="/reviews" className="text-primary text-sm font-medium hover:underline">
                            View All →
                        </Link>
                    }
                >
                    <div className="mb-4 flex flex-wrap gap-6 text-sm">
                        <span>
                            <span className="text-2xl font-bold text-orange-500 tabular-nums">{review.pending}</span> waiting for review
                        </span>
                        {review.revisions !== null && (
                            <span>
                                <span className="text-2xl font-bold text-indigo-500 tabular-nums">{review.revisions}</span> revision requests
                            </span>
                        )}
                        <span>
                            <span className="text-2xl font-bold text-slate-900 tabular-nums">{review.transferees}</span> transferees
                        </span>
                    </div>
                    {review.recent.length === 0 ? (
                        <p className="text-sm text-slate-500">Nothing is waiting for review.</p>
                    ) : (
                        <table className="w-full text-left text-sm">
                            <thead className="table-head">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Student Name</th>
                                    <th className="px-3 py-2 font-medium">Grade Level</th>
                                    <th className="px-3 py-2 font-medium">Date</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {review.recent.map((r) => (
                                    <tr key={r.id}>
                                        <td className="px-3 py-2">
                                            <Link
                                                href={route('enrollments.show', r.id)}
                                                className="hover:text-primary font-medium text-slate-900 hover:underline"
                                            >
                                                {r.name}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2 text-slate-600">
                                            Grade {r.grade_level}
                                            {r.strand ? ` · ${r.strand}` : ''}
                                        </td>
                                        <td className="px-3 py-2 text-slate-600">{r.encoded_on}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Block>
            )}

            {mySections && (
                <Block
                    title="My Sections"
                    action={
                        <Link href="/my-section" className="text-primary text-sm font-medium hover:underline">
                            Open →
                        </Link>
                    }
                >
                    {mySections.length === 0 ? (
                        <p className="text-sm text-slate-500">You are not the adviser of any section yet.</p>
                    ) : (
                        <ul className="space-y-3">
                            {mySections.map((s) => (
                                <li key={s.id} className="flex flex-wrap items-center justify-between gap-3">
                                    <Link
                                        href={`/my-section?section=${s.id}`}
                                        className="hover:text-primary font-medium text-slate-900 hover:underline"
                                    >
                                        {s.label}
                                    </Link>
                                    <CapacityBar filled={s.filled} capacity={s.capacity} />
                                </li>
                            ))}
                        </ul>
                    )}
                </Block>
            )}

            {sectionOverview && (
                <Block title="Section Overview">
                    <dl className="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
                        {[
                            ['Sections', sectionOverview.sections],
                            ['Seats', sectionOverview.capacity],
                            ['Learners placed', sectionOverview.assigned],
                        ].map(([l, v]) => (
                            <div key={l}>
                                <dt className="text-xs text-slate-500 uppercase">{l}</dt>
                                <dd className="text-2xl font-bold text-slate-900 tabular-nums">{v}</dd>
                            </div>
                        ))}
                    </dl>
                    <h3 className="mb-2 text-sm font-semibold text-slate-700">Action Required</h3>
                    <ul className="space-y-2 text-sm">
                        {sectionOverview.unassigned > 0 && (
                            <li>
                                <Link href="/section-assignments" className="text-amber-700 hover:underline">
                                    {sectionOverview.unassigned} enrolled learners are not in a section yet
                                </Link>
                            </li>
                        )}
                        {sectionOverview.without_adviser > 0 && (
                            <li>
                                <Link href="/sections" className="text-amber-700 hover:underline">
                                    {sectionOverview.without_adviser} sections have no adviser
                                </Link>
                            </li>
                        )}
                        {sectionOverview.full > 0 && <li className="text-red-700">{sectionOverview.full} sections are full</li>}
                        {sectionOverview.unassigned + sectionOverview.without_adviser + sectionOverview.full === 0 && (
                            <li className="text-emerald-700">Nothing needs attention.</li>
                        )}
                    </ul>
                </Block>
            )}

            {teaching && (
                <Block
                    title="Teaching Overview"
                    action={
                        <Link href="/my-students" className="text-primary text-sm font-medium hover:underline">
                            My Classes →
                        </Link>
                    }
                >
                    <p className="mb-4 text-sm text-slate-600">
                        <span className="text-2xl font-bold text-slate-900 tabular-nums">{teaching.classes}</span> classes ·{' '}
                        <span className="text-2xl font-bold text-slate-900 tabular-nums">{teaching.learners}</span> learners
                    </p>
                    <div className="grid grid-cols-2 gap-4 text-sm">
                        {[
                            ['By Subject', teaching.by_subject],
                            ['By Grade Level', teaching.by_grade],
                        ].map(([title, items]) => (
                            <div key={title as string}>
                                <h3 className="mb-2 font-semibold text-slate-700">{title as string}</h3>
                                <ul className="space-y-1">
                                    {(items as { label: string; classes: number }[]).map((i) => (
                                        <li key={i.label} className="flex justify-between gap-2">
                                            <span>{i.label}</span>
                                            <span className="tabular-nums">{i.classes}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </Block>
            )}

            {system && (
                <Block
                    title="System"
                    action={
                        <span className="flex items-center gap-1 text-sm font-medium text-emerald-600">
                            <Icon name="check_circle" /> Online
                        </span>
                    }
                >
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <h3 className="mb-2 text-sm font-semibold text-slate-700">User Role Distribution</h3>
                            <ul className="space-y-1 text-sm">
                                {system.roles.map((r) => (
                                    <li key={r.label} className="flex justify-between gap-2">
                                        <span>{r.label}</span>
                                        <span className="tabular-nums">{r.count}</span>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-2 text-xs text-slate-500">
                                {system.active_users} of {system.users} accounts active · {system.activity_today} changes today
                            </p>
                        </div>
                        <div>
                            <h3 className="mb-2 text-sm font-semibold text-slate-700">Recent Activity</h3>
                            <ul className="space-y-2 text-sm">
                                {system.recent.map((a) => (
                                    <li key={a.id}>
                                        <span className="font-medium">{a.user}</span> {a.event} {a.subject_type.toLowerCase()} {a.subject}
                                        <span className="block text-xs text-slate-500">{when.format(new Date(a.at))}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </Block>
            )}
        </div>
    );
}
