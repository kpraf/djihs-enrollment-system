import { BarChart, LineChart } from '@/components/charts';
import { Icon } from '@/components/icon';
import { type IconName } from '@/components/icons';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type Option } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Tally {
    total: number;
    male: number;
    female: number;
    pwd: number;
    balik_aral: number;
    transferee: number;
    indigenous: number;
    four_ps: number;
}

interface Report {
    overview: Tally;
    by_grade: (Tally & { grade: number; label: string })[];
    by_learner_type: { type: string; label: string; total: number }[];
    by_strand: (Tally & { code: string; name: string; track: string })[];
}

interface Comparison {
    a: string;
    b: string;
    overview: { a: Tally; b: Tally };
    by_grade: {
        label: string;
        a: number;
        b: number;
        change: number;
        male_a: number;
        male_b: number;
        female_a: number;
        female_b: number;
        pwd_a: number;
        pwd_b: number;
    }[];
    by_learner_type: { label: string; a: number; b: number }[];
}

interface Props {
    schoolYears: { id: number; name: string; is_active: boolean }[];
    filters: { school_year?: number; grade?: number | null; compare_a?: number; compare_b?: number };
    grades: Option<number>[];
    report: Report | null;
    trends: { year: string; total: number; male: number; female: number }[];
    comparison: Comparison | null;
}

const tabs: { key: string; label: string; icon: IconName }[] = [
    { key: 'overview', label: 'Overview', icon: 'dashboard' },
    { key: 'trends', label: 'Enrollment Trends', icon: 'trending_up' },
    { key: 'demographics', label: 'Demographics', icon: 'groups' },
    { key: 'distribution', label: 'Distribution', icon: 'bar_chart' },
    { key: 'strands', label: 'Senior High (Strands)', icon: 'school' },
    { key: 'compare', label: 'Year Comparison', icon: 'compare_arrows' },
    { key: 'report', label: 'Detailed Report', icon: 'table_view' },
];

const nf = new Intl.NumberFormat('en-PH');

export default function Analytics({ schoolYears, filters, grades, report, trends, comparison }: Props) {
    const [tab, setTab] = useState(comparison ? 'compare' : 'overview');
    const [cmpA, setCmpA] = useState(String(filters.compare_a ?? schoolYears[1]?.id ?? ''));
    const [cmpB, setCmpB] = useState(String(filters.compare_b ?? schoolYears[0]?.id ?? ''));
    const yearName = schoolYears.find((y) => y.id === filters.school_year)?.name;

    const apply = (changes: Record<string, unknown>) => {
        const merged: Record<string, unknown> = { ...filters, ...changes };
        const query: Record<string, string> = {};
        for (const [k, v] of Object.entries(merged)) {
            if (v !== '' && v !== null && v !== undefined) query[k] = String(v);
        }
        router.get('/analytics', query, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title="Analytics" />
            <PageHeader
                title="Enrollment Analytics"
                description={
                    yearName
                        ? `School year ${yearName}${filters.grade ? `, Grade ${filters.grade}` : ''}. Only enrolled learners are counted.`
                        : 'No school year yet.'
                }
                actions={
                    <>
                        <a
                            className="btn-outline"
                            href={route('analytics.export', { school_year: filters.school_year, grade: filters.grade ?? undefined, format: 'csv' })}
                        >
                            <Icon name="download" /> Export CSV
                        </a>
                        <a
                            className="btn-outline"
                            href={route('analytics.export', { school_year: filters.school_year, grade: filters.grade ?? undefined, format: 'xlsx' })}
                        >
                            <Icon name="table_view" /> Export Excel
                        </a>
                        <button type="button" className="btn-outline" onClick={() => window.print()}>
                            <Icon name="print" /> Print
                        </button>
                    </>
                }
            />

            <div className="panel mb-6 flex flex-wrap items-center gap-3 p-4">
                <select
                    className="field-input-sm h-10 w-auto"
                    value={filters.school_year ?? ''}
                    onChange={(e) => apply({ school_year: e.target.value })}
                    aria-label="School year"
                >
                    {schoolYears.map((y) => (
                        <option key={y.id} value={y.id}>
                            SY {y.name}
                        </option>
                    ))}
                </select>
                <select
                    className="field-input-sm h-10 w-auto"
                    value={filters.grade ?? ''}
                    onChange={(e) => apply({ grade: e.target.value })}
                    aria-label="Grade level"
                >
                    <option value="">All Grades</option>
                    {grades.map((g) => (
                        <option key={g.value} value={g.value}>
                            {g.label}
                        </option>
                    ))}
                </select>
                <button type="button" className="btn-secondary" onClick={() => router.get('/analytics')}>
                    Reset
                </button>
            </div>

            <div className="mb-6 flex flex-wrap gap-2" role="tablist">
                {tabs.map((t) => (
                    <button
                        key={t.key}
                        type="button"
                        role="tab"
                        aria-selected={tab === t.key}
                        onClick={() => setTab(t.key)}
                        className={cn(
                            'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold whitespace-nowrap',
                            tab === t.key ? 'bg-primary text-white' : 'bg-white text-slate-600 hover:bg-slate-100',
                        )}
                    >
                        <Icon name={t.icon} /> {t.label}
                    </button>
                ))}
            </div>

            {report && tab === 'overview' && (
                <>
                    <h2 className="mb-4 text-xl font-bold text-slate-900">Key Highlights</h2>
                    <div className="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <StatCard
                            label="Total Enrolled"
                            value={nf.format(report.overview.total)}
                            hint={`${report.overview.male} male · ${report.overview.female} female`}
                        />
                        <StatCard label="Transferees" value={report.overview.transferee} tone="indigo" hint="Moved in from other schools" />
                        <StatCard label="Balik-Aral" value={report.overview.balik_aral} tone="orange" hint="Returning learners" />
                        <StatCard
                            label="Learners with Disability"
                            value={report.overview.pwd}
                            tone="purple"
                            hint={`${report.overview.indigenous} IP · ${report.overview.four_ps} 4Ps`}
                        />
                    </div>
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <ChartPanel title="By Grade Level">
                            <BarChart
                                label="Enrolled learners by grade"
                                labels={report.by_grade.map((g) => g.label)}
                                series={[{ label: 'Enrolled', data: report.by_grade.map((g) => g.total) }]}
                            />
                        </ChartPanel>
                        <ChartPanel title="Enrollment Over the Years">
                            <LineChart
                                label="Enrolled learners per school year"
                                labels={trends.map((t) => t.year)}
                                series={[{ label: 'Enrolled', data: trends.map((t) => t.total) }]}
                            />
                        </ChartPanel>
                    </div>
                </>
            )}

            {tab === 'trends' && (
                <ChartPanel title="Enrollment Trends by Sex">
                    <LineChart
                        label="Male and female learners per school year"
                        height={320}
                        labels={trends.map((t) => t.year)}
                        series={[
                            { label: 'Male', data: trends.map((t) => t.male) },
                            { label: 'Female', data: trends.map((t) => t.female) },
                        ]}
                    />
                </ChartPanel>
            )}

            {report && tab === 'demographics' && (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <ChartPanel title="Sex by Grade Level">
                        <BarChart
                            label="Male and female learners by grade"
                            labels={report.by_grade.map((g) => g.label)}
                            series={[
                                { label: 'Male', data: report.by_grade.map((g) => g.male) },
                                { label: 'Female', data: report.by_grade.map((g) => g.female) },
                            ]}
                        />
                    </ChartPanel>
                    <ChartPanel title="Inclusion">
                        <BarChart
                            horizontal
                            label="Learners with disability, Indigenous Peoples and 4Ps"
                            labels={['With disability', 'Indigenous Peoples', '4Ps beneficiaries']}
                            series={[{ label: 'Learners', data: [report.overview.pwd, report.overview.indigenous, report.overview.four_ps] }]}
                        />
                    </ChartPanel>
                </div>
            )}

            {report && tab === 'distribution' && (
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <ChartPanel title="By Type of Learner">
                        <BarChart
                            horizontal
                            label="Enrolled learners by type"
                            labels={report.by_learner_type.map((t) => t.label)}
                            series={[{ label: 'Learners', data: report.by_learner_type.map((t) => t.total) }]}
                        />
                    </ChartPanel>
                    <ChartPanel title="By Grade Level">
                        <BarChart
                            label="Enrolled learners by grade"
                            labels={report.by_grade.map((g) => g.label)}
                            series={[{ label: 'Enrolled', data: report.by_grade.map((g) => g.total) }]}
                        />
                    </ChartPanel>
                </div>
            )}

            {report && tab === 'strands' && (
                <ChartPanel title="Senior High School Strands">
                    <BarChart
                        horizontal
                        height={Math.max(220, report.by_strand.length * 56)}
                        label="Male and female learners by strand"
                        labels={report.by_strand.map((s) => s.code)}
                        series={[
                            { label: 'Male', data: report.by_strand.map((s) => s.male) },
                            { label: 'Female', data: report.by_strand.map((s) => s.female) },
                        ]}
                    />
                </ChartPanel>
            )}

            {tab === 'compare' && (
                <div className="space-y-6">
                    <div className="panel flex flex-wrap items-end gap-3 p-4">
                        {[
                            ['Year A', cmpA, setCmpA],
                            ['Year B', cmpB, setCmpB],
                        ].map(([label, value, set]) => (
                            <label key={label as string} className="flex flex-col text-xs text-slate-600">
                                {label as string}
                                <select
                                    className="field-input-sm mt-1 h-10"
                                    value={value as string}
                                    onChange={(e) => (set as (v: string) => void)(e.target.value)}
                                >
                                    {schoolYears.map((y) => (
                                        <option key={y.id} value={y.id}>
                                            SY {y.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ))}
                        <button type="button" className="btn-primary" onClick={() => apply({ compare_a: cmpA, compare_b: cmpB })}>
                            <Icon name="compare_arrows" /> Compare
                        </button>
                    </div>
                    {comparison && (
                        <>
                            <ChartPanel title={`Grade Levels: ${comparison.a} vs ${comparison.b}`}>
                                <BarChart
                                    label="Enrolled learners by grade in two school years"
                                    labels={comparison.by_grade.map((g) => g.label)}
                                    series={[
                                        { label: comparison.a, data: comparison.by_grade.map((g) => g.a) },
                                        { label: comparison.b, data: comparison.by_grade.map((g) => g.b) },
                                    ]}
                                />
                            </ChartPanel>
                            <Table
                                head={['Grade', comparison.a, comparison.b, 'Δ Change', 'Male A / B', 'Female A / B', 'PWD A / B']}
                                rows={comparison.by_grade.map((g) => [
                                    g.label,
                                    g.a,
                                    g.b,
                                    `${g.change > 0 ? '+' : ''}${g.change}`,
                                    `${g.male_a} / ${g.male_b}`,
                                    `${g.female_a} / ${g.female_b}`,
                                    `${g.pwd_a} / ${g.pwd_b}`,
                                ])}
                            />
                        </>
                    )}
                </div>
            )}

            {report && tab === 'report' && (
                <Table
                    head={['Grade Level', 'Total', 'Male', 'Female', 'PWD', 'Balik-Aral', 'Transferee', 'IP', '4Ps']}
                    rows={[
                        ...report.by_grade.map((g) => [
                            g.label,
                            g.total,
                            g.male,
                            g.female,
                            g.pwd,
                            g.balik_aral,
                            g.transferee,
                            g.indigenous,
                            g.four_ps,
                        ]),
                        [
                            'Total',
                            report.overview.total,
                            report.overview.male,
                            report.overview.female,
                            report.overview.pwd,
                            report.overview.balik_aral,
                            report.overview.transferee,
                            report.overview.indigenous,
                            report.overview.four_ps,
                        ],
                    ]}
                />
            )}
        </AppLayout>
    );
}

function ChartPanel({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section className="panel p-6">
            <h3 className="mb-4 text-lg font-semibold text-slate-900">{title}</h3>
            {children}
        </section>
    );
}

function Table({ head, rows }: { head: string[]; rows: (string | number)[][] }) {
    return (
        <div className="panel overflow-hidden">
            <div className="relative overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="table-head">
                        <tr>
                            {head.map((h, i) => (
                                <th key={h} className={cn('px-4 py-3 font-medium whitespace-nowrap', i === 0 ? 'text-left' : 'text-right')}>
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((r, i) => (
                            <tr key={i} className={cn('hover:bg-slate-50', i === rows.length - 1 && r[0] === 'Total' && 'bg-slate-50 font-semibold')}>
                                {r.map((c, j) => (
                                    <td
                                        key={j}
                                        className={cn('px-4 py-3', j === 0 ? 'text-left text-slate-900' : 'text-right text-slate-700 tabular-nums')}
                                    >
                                        {c}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
