import { BarChart, CONTEXT, DonutChart, formatNumber, LineChart, Meter, TableToggle } from '@/components/charts';
import { Icon } from '@/components/icon';
import { type IconName } from '@/components/icons';
import { PageHeader } from '@/components/page-header';
import { ChartSkeleton, TableSkeleton, TilesSkeleton } from '@/components/skeleton';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Deferred, Head, Link, router } from '@inertiajs/react';

interface Summary {
    enrolled: number;
    new: number;
    returning: number;
    male: number;
    female: number;
}

interface TableRow {
    grade: number;
    strand: string | null;
    track: string | null;
    male: number;
    female: number;
    total: number;
    before: number | null;
}

interface Rates {
    previous_year: string | null;
    growth_rate: number | null;
    transition_rate: number | null;
    promotion_rate: number | null;
    retention_rate: number | null;
    dropout_rate: number;
    grades: {
        grade: number;
        label: string;
        enrollment: number;
        promotion_rate: number | null;
        retention_rate: number | null;
        dropout_rate: number;
        status: 'good' | 'watch' | 'concern';
    }[];
    resources: {
        sections: number;
        capacity: number;
        seated: number;
        learners_per_section: number | null;
        fill_rate: number | null;
        advisers: number;
    };
    insights: { tone: 'good' | 'watch' | 'concern'; text: string }[];
}

interface Props {
    schoolYears: { id: number; name: string; is_active: boolean }[];
    filters: { school_year: number | null; compare: number | null };
    compareName: string | null;
    summary: { now: Summary; before: Summary | null } | null;
    profile: { learner_types: { type: string; label: string; total: number }[]; inclusion: { label: string; total: number }[] } | null;
    // Loaded right after the page; undefined until then.
    table?: TableRow[];
    trends?: { year: string; total: number; male: number; female: number }[];
    strandTrends?: { code: string; name: string; years: { year: string; total: number }[] }[];
    attention?: { label: string; count: number; href: string | null }[];
    rates?: Rates | null;
}

const pct = (v: number | null) => (v === null ? 'n/a' : `${v}%`);
const share = (n: number, of: number) => (of ? `${Math.round((n / of) * 1000) / 10}%` : '—');

// Status uses an icon and a word, never color alone.
const statusStyle = {
    good: { cls: 'bg-green-100 text-green-800', icon: 'check_circle' as IconName, label: 'Good' },
    watch: { cls: 'bg-amber-100 text-amber-800', icon: 'warning' as IconName, label: 'Watch' },
    concern: { cls: 'bg-red-100 text-red-800', icon: 'error' as IconName, label: 'Concern' },
};

/**
 * Reports: one page for what used to be Analytics and Metrics, read top to
 * bottom like the questions the school asks.
 */
export default function Reports({ schoolYears, filters, compareName, summary, table, trends, strandTrends, profile, attention, rates }: Props) {
    const can = useCan();
    const yearName = schoolYears.find((y) => y.id === filters.school_year)?.name;
    const go = (next: Partial<Props['filters']>) =>
        router.get(
            route('reports.index'),
            { school_year: filters.school_year ?? undefined, compare: filters.compare ?? '', ...next },
            { preserveScroll: true, preserveState: true, replace: true },
        );

    return (
        <AppLayout>
            <Head title={yearName ? `DJIHS Enrollment Report - SY ${yearName}` : 'Reports'} />
            {/* Only on paper: whose report this is and when it was printed. */}
            <p className="mb-2 hidden text-sm text-slate-600 print:block">
                Don Jose Integrated High School · Enrollment report{yearName ? ` for school year ${yearName}` : ''} · printed{' '}
                {new Intl.DateTimeFormat('en-PH', { dateStyle: 'long' }).format(new Date())}
            </p>
            <PageHeader
                title="Reports"
                description={
                    yearName
                        ? `School year ${yearName}${compareName ? `, compared with ${compareName}` : ''}. Only enrolled learners are counted.`
                        : 'No school year yet.'
                }
                actions={
                    yearName && (
                        <div className="flex flex-wrap gap-2 print:hidden">
                            <a className="btn-outline" href={route('reports.export', { school_year: filters.school_year, format: 'csv' })}>
                                <Icon name="download" /> Export CSV
                            </a>
                            <a className="btn-outline" href={route('reports.export', { school_year: filters.school_year, format: 'xlsx' })}>
                                <Icon name="table_view" /> Export Excel
                            </a>
                            <button type="button" className="btn-outline" onClick={() => window.print()}>
                                <Icon name="print" /> Print
                            </button>
                        </div>
                    )
                }
            />

            {!summary ? (
                <div className="panel px-6 py-12 text-center text-slate-500">Create a school year to see reports.</div>
            ) : (
                <div className="space-y-8">
                    <div className="panel flex flex-wrap items-end gap-4 p-4 print:hidden">
                        <label className="flex flex-col text-xs text-slate-600">
                            School year
                            <select
                                className="field-input-sm mt-1"
                                value={filters.school_year ?? ''}
                                onChange={(e) => go({ school_year: Number(e.target.value), compare: null })}
                            >
                                {schoolYears.map((y) => (
                                    <option key={y.id} value={y.id}>
                                        SY {y.name}
                                        {y.is_active ? ' (active)' : ''}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex flex-col text-xs text-slate-600">
                            Compared with
                            <select
                                className="field-input-sm mt-1"
                                value={filters.compare ?? ''}
                                onChange={(e) => go({ compare: e.target.value ? Number(e.target.value) : 0 })}
                            >
                                <option value="0">No comparison</option>
                                {schoolYears
                                    .filter((y) => y.id !== filters.school_year)
                                    .map((y) => (
                                        <option key={y.id} value={y.id}>
                                            SY {y.name}
                                        </option>
                                    ))}
                            </select>
                        </label>
                    </div>

                    <Section title="This year at a glance">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <Tile label="Enrolled" value={summary.now.enrolled} before={summary.before?.enrolled} compareName={compareName} />
                            <Tile label="New to the school" value={summary.now.new} before={summary.before?.new} compareName={compareName} />
                            <Tile label="Returning" value={summary.now.returning} before={summary.before?.returning} compareName={compareName} />
                        </div>
                        {summary.now.enrolled > 0 && (
                            <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                                <DonutChart
                                    title="Male and female"
                                    parts={[
                                        { label: 'Male', value: summary.now.male },
                                        { label: 'Female', value: summary.now.female },
                                    ]}
                                />
                                <Deferred data="table" fallback={<ChartSkeleton what="the track split" height={112} />}>
                                    <TrackDonut rows={table ?? []} />
                                </Deferred>
                            </div>
                        )}
                    </Section>

                    <Section title="Where learners are" note="Enrolled learners by grade and strand, like the workbook's MONITORING tab.">
                        <Deferred data="table" fallback={<ChartSkeleton what="learners by grade and strand" height={240} />}>
                            <WhereLearnersAre rows={table ?? []} yearName={yearName ?? 'This year'} compareName={compareName} />
                        </Deferred>
                    </Section>

                    <Section title="How enrollment is changing">
                        <Deferred
                            data={['trends', 'strandTrends']}
                            fallback={
                                <div className="space-y-4">
                                    <ChartSkeleton what="enrollment trends" />
                                    <ChartSkeleton what="trends by strand" height={130} />
                                </div>
                            }
                        >
                            <Trends trends={trends ?? []} strandTrends={strandTrends ?? []} />
                        </Deferred>
                    </Section>
                    {profile && (
                        <Section title="Who the learners are">
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                <div className="panel p-5">
                                    <h3 className="mb-3 font-semibold text-slate-900">Type of learner</h3>
                                    <BarChart
                                        horizontal
                                        values
                                        labels={[...profile.learner_types].sort((a, b) => b.total - a.total).map((t) => t.label)}
                                        series={[
                                            {
                                                label: 'Learners',
                                                data: [...profile.learner_types].sort((a, b) => b.total - a.total).map((t) => t.total),
                                            },
                                        ]}
                                        label={`Type of learner: ${profile.learner_types.map((t) => `${t.label} ${t.total}`).join(', ')}`}
                                    />
                                    <TableToggle>
                                        <ShareTable
                                            title="Type of learner"
                                            rows={profile.learner_types.map((t) => ({ label: t.label, total: t.total }))}
                                            of={summary.now.enrolled}
                                        />
                                    </TableToggle>
                                </div>
                                <div className="panel p-5">
                                    <h3 className="mb-1 font-semibold text-slate-900">Inclusion</h3>
                                    <p className="mb-4 text-sm text-slate-500">
                                        Share of the {formatNumber(summary.now.enrolled)} enrolled learners.
                                    </p>
                                    <div className="space-y-5">
                                        {profile.inclusion.map((r) => (
                                            <Meter key={r.label} label={r.label} value={r.total} of={summary.now.enrolled} />
                                        ))}
                                    </div>
                                    <TableToggle>
                                        <ShareTable title="Inclusion" rows={profile.inclusion} of={summary.now.enrolled} />
                                    </TableToggle>
                                </div>
                            </div>
                        </Section>
                    )}

                    <Section title="Needs attention">
                        <Deferred data="attention" fallback={<TableSkeleton what="the list of things that need attention" rows={5} columns={2} />}>
                            <AttentionList items={attention ?? []} />
                        </Deferred>
                    </Section>

                    {can('view-metrics') && (
                        <Deferred
                            data="rates"
                            fallback={
                                <section>
                                    <h2 className="text-xl font-bold text-slate-900">DepEd indicators</h2>
                                    <TilesSkeleton what="the DepEd indicators" className="mt-3" />
                                </section>
                            }
                        >
                            <>{rates && <RatesSection rates={rates} />}</>
                        </Deferred>
                    )}
                </div>
            )}
        </AppLayout>
    );
}

function Trends({ trends, strandTrends }: { trends: NonNullable<Props['trends']>; strandTrends: NonNullable<Props['strandTrends']> }) {
    if (trends.length < 2) {
        return <p className="panel p-5 text-sm text-slate-500">Trends appear once there are two or more school years.</p>;
    }

    return (
        <div className="space-y-4">
            <div className="panel p-5">
                <h3 className="mb-3 font-semibold text-slate-900">Enrolled learners per school year</h3>
                <LineChart
                    labels={trends.map((t) => t.year)}
                    series={[{ label: 'Enrolled', data: trends.map((t) => t.total) }]}
                    height={220}
                    label="Enrolled learners per school year"
                />
            </div>
            {strandTrends.length > 0 && <StrandTrends strands={strandTrends} />}
        </div>
    );
}

function AttentionList({ items }: { items: NonNullable<Props['attention']> }) {
    return (
        <ul className="panel divide-y divide-slate-100">
            {items.map((a) => (
                <li key={a.label} className="flex flex-wrap items-center gap-3 px-5 py-3.5">
                    {a.count > 0 ? <Icon name="warning" className="text-amber-600" /> : <Icon name="check_circle" className="text-emerald-600" />}
                    <span className="flex-1 text-slate-700">{a.label}</span>
                    <span className="font-semibold text-slate-900 tabular-nums">{a.count > 0 ? a.count : 'None'}</span>
                    {a.href && a.count > 0 && (
                        <Link href={a.href} className="text-primary text-sm font-semibold underline print:hidden">
                            View
                        </Link>
                    )}
                </li>
            ))}
        </ul>
    );
}

function Section({ title, note, children }: { title: string; note?: string; children: React.ReactNode }) {
    return (
        <section>
            {/* On paper the heading never sits alone at the bottom of a page. */}
            <div className="break-after-avoid">
                <h2 className="text-xl font-bold text-slate-900">{title}</h2>
                {note && <p className="mt-0.5 text-sm text-slate-500">{note}</p>}
            </div>
            <div className="mt-3">{children}</div>
        </section>
    );
}

function Change({ now, before, compareName }: { now: number; before: number | null | undefined; compareName: string | null }) {
    if (before === null || before === undefined || !compareName) return null;
    const diff = now - before;
    return (
        <span className="text-sm text-slate-600">
            {diff === 0 ? 'No change' : `${diff > 0 ? '▲' : '▼'} ${Math.abs(diff)}`} from {compareName}
        </span>
    );
}

function Tile({ label, value, before, compareName }: { label: string; value: number; before?: number; compareName: string | null }) {
    return (
        <div className="panel p-5">
            <p className="text-sm font-medium text-slate-600">{label}</p>
            <p className="mt-1 text-3xl font-bold text-slate-900">{formatNumber(value)}</p>
            <p className="mt-1">
                <Change now={value} before={before} compareName={compareName} />
            </p>
        </div>
    );
}

/**
 * Learners per grade level (7 to 12), then senior high strands per grade;
 * this year in green beside the compared year in grey.
 */
function WhereLearnersAre({ rows, yearName, compareName }: { rows: TableRow[]; yearName: string; compareName: string | null }) {
    if (rows.length === 0) return <p className="panel p-5 text-sm text-slate-500">No enrolled learners yet.</p>;
    const grades = [...new Set(rows.map((r) => r.grade))];
    const perGrade = (g: number, key: 'total' | 'before') => rows.filter((r) => r.grade === g).reduce((n, r) => n + (r[key] ?? 0), 0);
    const seniorGrades = grades.filter((g) => rows.some((r) => r.grade === g && r.strand !== null));
    const pair = (now: number[], before: number[]) =>
        compareName
            ? [
                  { label: yearName, data: now },
                  { label: compareName, data: before, color: CONTEXT },
              ]
            : [{ label: yearName, data: now }];

    return (
        <div className="panel p-5">
            <h3 className="mb-3 font-semibold text-slate-900">By grade level</h3>
            <BarChart
                values
                height={240}
                labels={grades.map((g) => `Grade ${g}`)}
                series={pair(
                    grades.map((g) => perGrade(g, 'total')),
                    grades.map((g) => perGrade(g, 'before')),
                )}
                label={`Learners by grade level: ${grades
                    .map((g) => `Grade ${g} ${perGrade(g, 'total')}${compareName ? ` (${perGrade(g, 'before')} in ${compareName})` : ''}`)
                    .join(', ')}`}
            />

            {seniorGrades.length > 0 && (
                <div className="mt-8 grid grid-cols-1 gap-x-8 gap-y-6 xl:grid-cols-2">
                    {seniorGrades.map((g) => {
                        const list = rows.filter((r) => r.grade === g && r.strand !== null);
                        return (
                            <div key={g}>
                                <h3 className="mb-3 font-semibold text-slate-900">
                                    Grade {g} by strand <span className="font-normal text-slate-500">· {perGrade(g, 'total')} learners</span>
                                </h3>
                                <BarChart
                                    horizontal
                                    values
                                    labels={list.map((r) => r.strand as string)}
                                    series={pair(
                                        list.map((r) => r.total),
                                        list.map((r) => r.before ?? 0),
                                    )}
                                    label={`Grade ${g} learners by strand: ${list
                                        .map((r) => `${r.strand} ${r.total}${compareName ? ` (${r.before ?? 0} in ${compareName})` : ''}`)
                                        .join(', ')}`}
                                />
                            </div>
                        );
                    })}
                </div>
            )}
            <TableToggle label="Show the full table, like the workbook's MONITORING tab">
                <GradeStrandTable rows={rows} compareName={compareName} />
            </TableToggle>
        </div>
    );
}

function TrackDonut({ rows }: { rows: TableRow[] }) {
    const academic = rows.filter((r) => r.track === 'academic').reduce((n, r) => n + r.total, 0);
    const tvl = rows.filter((r) => r.track === 'tvl').reduce((n, r) => n + r.total, 0);
    if (academic + tvl === 0) return <></>;
    return (
        <DonutChart
            title="Senior high track"
            parts={[
                { label: 'Academic', value: academic },
                { label: 'TVL', value: tvl },
            ]}
        />
    );
}

function GradeStrandTable({ rows, compareName }: { rows: TableRow[]; compareName: string | null }) {
    if (rows.length === 0) return <p className="panel p-5 text-sm text-slate-500">No enrolled learners yet.</p>;

    const grades = [...new Set(rows.map((r) => r.grade))];
    const sum = (list: TableRow[], k: 'male' | 'female' | 'total') => list.reduce((n, r) => n + r[k], 0);
    const sumBefore = (list: TableRow[]) => list.reduce((n, r) => n + (r.before ?? 0), 0);
    const diff = (now: number, before: number) => (now === before ? '0' : `${now > before ? '+' : '−'}${Math.abs(now - before)}`);

    const cell = 'px-2 py-2.5 text-right tabular-nums sm:px-5';

    return (
        <div className="panel relative overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="table-head">
                    <tr>
                        <th className="px-2 py-3 text-left font-medium sm:px-5">Strand</th>
                        <th className="px-2 py-3 text-right font-medium sm:px-5">Male</th>
                        <th className="px-2 py-3 text-right font-medium sm:px-5">Female</th>
                        <th className="px-2 py-3 text-right font-medium sm:px-5">Total</th>
                        {compareName && <th className="px-2 py-3 text-right font-medium sm:px-5">Change</th>}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {grades.map((g) => {
                        const list = rows.filter((r) => r.grade === g);
                        return [
                            <tr key={`${g}-heading`} className="bg-green-50/60">
                                <th scope="rowgroup" colSpan={compareName ? 5 : 4} className="text-primary px-2 py-2 text-left font-semibold sm:px-5">
                                    Grade {g}
                                </th>
                            </tr>,
                            ...list.map((r) => (
                                <tr key={`${g}-${r.strand}`}>
                                    <td className="px-2 py-2.5 whitespace-nowrap text-slate-900 sm:px-5">{r.strand ?? 'Junior High'}</td>
                                    <td className={cell}>{r.male}</td>
                                    <td className={cell}>{r.female}</td>
                                    <td className={cn(cell, 'font-medium')}>{r.total}</td>
                                    {compareName && <td className={cn(cell, 'text-slate-600')}>{diff(r.total, r.before ?? 0)}</td>}
                                </tr>
                            )),
                            list.length > 1 && (
                                <tr key={`${g}-subtotal`} className="bg-slate-50 font-semibold">
                                    <td className="px-2 py-2.5 whitespace-nowrap sm:px-5">Grade {g} total</td>
                                    <td className={cell}>{sum(list, 'male')}</td>
                                    <td className={cell}>{sum(list, 'female')}</td>
                                    <td className={cell}>{sum(list, 'total')}</td>
                                    {compareName && <td className={cell}>{diff(sum(list, 'total'), sumBefore(list))}</td>}
                                </tr>
                            ),
                        ];
                    })}
                    <tr className="border-t-2 border-slate-300 font-bold text-slate-900">
                        <td className="px-2 py-3 whitespace-nowrap sm:px-5">All grades</td>
                        <td className={cell}>{sum(rows, 'male')}</td>
                        <td className={cell}>{sum(rows, 'female')}</td>
                        <td className={cell}>{sum(rows, 'total')}</td>
                        {compareName && <td className={cell}>{diff(sum(rows, 'total'), sumBefore(rows))}</td>}
                    </tr>
                </tbody>
            </table>
        </div>
    );
}

/** One small chart per strand, all on the same scale so they can be compared. */
function StrandTrends({ strands }: { strands: NonNullable<Props['strandTrends']> }) {
    const top = Math.max(...strands.flatMap((s) => s.years.map((y) => y.total)));
    const max = Math.ceil((top * 1.1) / 5) * 5 || 5;

    return (
        <div className="panel p-5">
            <h3 className="font-semibold text-slate-900">By strand</h3>
            <p className="mb-3 text-sm text-slate-500">Each chart uses the same scale, from 0 to {max} learners.</p>
            <div className="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 xl:grid-cols-3">
                {strands.map((s) => {
                    const latest = s.years[s.years.length - 1]?.total ?? 0;
                    return (
                        <div key={s.code}>
                            <p className="text-sm font-semibold text-slate-900">
                                {s.code} <span className="font-normal text-slate-500">· {latest} now</span>
                            </p>
                            <LineChart
                                labels={s.years.map((y) => y.year)}
                                series={[{ label: s.code, data: s.years.map((y) => y.total) }]}
                                height={130}
                                max={max}
                                label={`${s.name} learners per school year`}
                            />
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function ShareTable({ title, rows, of }: { title: string; rows: { label: string; total: number }[]; of: number }) {
    return (
        <div className="panel relative overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="table-head">
                    <tr>
                        <th className="px-5 py-3 text-left font-medium">{title}</th>
                        <th className="px-5 py-3 text-right font-medium">Learners</th>
                        <th className="px-5 py-3 text-right font-medium">Share</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.map((r) => (
                        <tr key={r.label}>
                            <td className="px-5 py-2.5 text-slate-900">{r.label}</td>
                            <td className="px-5 py-2.5 text-right tabular-nums">{r.total === 0 ? 'None' : r.total}</td>
                            <td className="px-5 py-2.5 text-right text-slate-600 tabular-nums">{r.total === 0 ? '—' : share(r.total, of)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function RatesSection({ rates }: { rates: Rates }) {
    const tiles: [string, string, string][] = [
        ['Transition rate', pct(rates.transition_rate), 'Grade 10 completers who continued to Grade 11 here'],
        ['Promotion rate', pct(rates.promotion_rate), 'Learners who moved up a grade from last year'],
        ['Retention rate', pct(rates.retention_rate), "Last year's learners who came back"],
        ['Dropout rate', pct(rates.dropout_rate), 'Learners marked dropped this year'],
    ];

    return (
        <Section title="DepEd indicators" note="Shown to administrators only.">
            <div className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {tiles.map(([label, value, help]) => (
                        <div key={label} className="panel p-5">
                            <p className="text-sm font-medium text-slate-600">{label}</p>
                            <p className="mt-1 text-3xl font-bold text-slate-900 tabular-nums">{value}</p>
                            <p className="mt-1 text-sm text-slate-500">{help}</p>
                        </div>
                    ))}
                </div>

                <div className="panel relative overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="table-head">
                            <tr>
                                <th className="px-5 py-3 text-left font-medium">Grade</th>
                                <th className="px-5 py-3 text-right font-medium">Enrolled</th>
                                <th className="px-5 py-3 text-right font-medium">Promotion</th>
                                <th className="px-5 py-3 text-right font-medium">Retention</th>
                                <th className="px-5 py-3 text-right font-medium">Dropout</th>
                                <th className="px-5 py-3 text-left font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rates.grades.map((g) => {
                                const st = statusStyle[g.status];
                                return (
                                    <tr key={g.grade}>
                                        <td className="px-5 py-2.5 text-slate-900">{g.label}</td>
                                        <td className="px-5 py-2.5 text-right tabular-nums">{g.enrollment}</td>
                                        <td className="px-5 py-2.5 text-right tabular-nums">{pct(g.promotion_rate)}</td>
                                        <td className="px-5 py-2.5 text-right tabular-nums">{pct(g.retention_rate)}</td>
                                        <td className="px-5 py-2.5 text-right tabular-nums">{pct(g.dropout_rate)}</td>
                                        <td className="px-5 py-2.5">
                                            <span
                                                className={cn(
                                                    'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                                    st.cls,
                                                )}
                                            >
                                                <Icon name={st.icon} className="text-sm" /> {st.label}
                                            </span>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div className="panel p-5">
                        <h3 className="mb-3 font-semibold text-slate-900">Sections and seats</h3>
                        <Meter label="Seats filled" value={rates.resources.seated} of={rates.resources.capacity} />
                        <dl className="mt-4 space-y-1.5 text-sm">
                            {[
                                ['Active sections', rates.resources.sections],
                                ['Advisers assigned', rates.resources.advisers],
                                ['Learners per section', rates.resources.learners_per_section ?? 'n/a'],
                            ].map(([k, v]) => (
                                <div key={k} className="flex justify-between gap-4">
                                    <dt className="text-slate-600">{k}</dt>
                                    <dd className="font-medium text-slate-900 tabular-nums">{v}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                    <div className="panel p-5">
                        <h3 className="mb-2 font-semibold text-slate-900">What the numbers say</h3>
                        <ul className="space-y-2 text-sm">
                            {rates.insights.map((i) => (
                                <li key={i.text} className="flex items-start gap-2">
                                    <Icon
                                        name={statusStyle[i.tone].icon}
                                        className={cn(
                                            'mt-px',
                                            i.tone === 'good' ? 'text-emerald-600' : i.tone === 'watch' ? 'text-amber-600' : 'text-red-600',
                                        )}
                                    />
                                    <span className="text-slate-700">{i.text}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>
        </Section>
    );
}
