import { LineChart } from '@/components/charts';
import { Icon } from '@/components/icon';
import { type IconName } from '@/components/icons';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Metrics {
    school_year: string;
    previous_year: string | null;
    total_enrollment: number;
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
        learners_per_section: number | null;
        fill_rate: number | null;
        nearly_full: number;
        without_adviser: number;
        unassigned_learners: number;
        advisers: number;
    };
    trends: { year: string; enrollment: number; dropout_rate: number }[];
    insights: { tone: 'good' | 'watch' | 'concern'; text: string }[];
}

const tabs: { key: string; label: string; icon: IconName }[] = [
    { key: 'access', label: 'Access & Participation', icon: 'groups' },
    { key: 'efficiency', label: 'Internal Efficiency', icon: 'speed' },
    { key: 'resources', label: 'Resources & Trends', icon: 'inventory_2' },
    { key: 'insights', label: 'Insights', icon: 'lightbulb' },
];

const pct = (v: number | null) => (v === null ? 'n/a' : `${v}%`);

// Status uses an icon and a word, never color alone.
const statusStyle = {
    good: { cls: 'bg-green-100 text-green-800', icon: 'check_circle' as IconName, label: 'Good' },
    watch: { cls: 'bg-amber-100 text-amber-800', icon: 'warning' as IconName, label: 'Watch' },
    concern: { cls: 'bg-red-100 text-red-800', icon: 'error' as IconName, label: 'Concern' },
};

export default function MetricsPage({
    schoolYears,
    selectedYear,
    metrics,
}: {
    schoolYears: { id: number; name: string }[];
    selectedYear: number | null;
    metrics: Metrics | null;
}) {
    const [tab, setTab] = useState('access');

    return (
        <AppLayout>
            <Head title="Metrics" />
            <PageHeader
                title="Enrollment Metrics"
                description={
                    metrics
                        ? `School year ${metrics.school_year}${metrics.previous_year ? `, compared with ${metrics.previous_year}` : ''}.`
                        : 'No school year yet.'
                }
                actions={
                    <>
                        <select
                            className="field-input-sm h-10 w-auto"
                            value={selectedYear ?? ''}
                            onChange={(e) => router.get('/metrics', { school_year: e.target.value }, { preserveState: true })}
                            aria-label="School year"
                        >
                            {schoolYears.map((y) => (
                                <option key={y.id} value={y.id}>
                                    SY {y.name}
                                </option>
                            ))}
                        </select>
                        <a className="btn-outline" href={route('metrics.export', { school_year: selectedYear ?? undefined, format: 'csv' })}>
                            <Icon name="download" /> Export CSV
                        </a>
                        <a className="btn-primary" href={route('metrics.export', { school_year: selectedYear ?? undefined, format: 'xlsx' })}>
                            <Icon name="table_view" /> Export Excel
                        </a>
                    </>
                }
            />

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

            {metrics && tab === 'access' && (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <StatCard label="Total Enrollment" value={metrics.total_enrollment} hint="Enrolled learners" />
                    <StatCard
                        label="Growth"
                        value={pct(metrics.growth_rate)}
                        tone={metrics.growth_rate !== null && metrics.growth_rate < 0 ? 'orange' : 'green'}
                        hint={metrics.previous_year ? `Compared with ${metrics.previous_year}` : 'No previous year to compare'}
                    />
                    <StatCard
                        label="Transition Rate"
                        value={pct(metrics.transition_rate)}
                        tone="indigo"
                        hint="Grade 10 completers who continued to Grade 11"
                    />
                </div>
            )}

            {metrics && tab === 'efficiency' && (
                <>
                    <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <StatCard label="Promotion Rate" value={pct(metrics.promotion_rate)} hint="Moved up a grade from last year" />
                        <StatCard
                            label="Retention Rate"
                            value={pct(metrics.retention_rate)}
                            tone="indigo"
                            hint="Came back this year (Grade 12 completers excluded)"
                        />
                        <StatCard
                            label="Dropout Rate"
                            value={pct(metrics.dropout_rate)}
                            tone={metrics.dropout_rate > 2 ? 'red' : 'green'}
                            hint="Marked dropped this year"
                        />
                    </div>
                    <h2 className="mb-4 text-xl font-bold text-slate-900">Grade-Level Breakdown</h2>
                    <div className="panel overflow-hidden">
                        <div className="relative overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="table-head">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">Grade Level</th>
                                        <th className="px-4 py-3 text-right font-medium">Enrollment</th>
                                        <th className="px-4 py-3 text-right font-medium">Promotion Rate</th>
                                        <th className="px-4 py-3 text-right font-medium">Retention Rate</th>
                                        <th className="px-4 py-3 text-right font-medium">Dropout Rate</th>
                                        <th className="px-4 py-3 text-left font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {metrics.grades.map((g) => (
                                        <tr key={g.grade} className="hover:bg-slate-50">
                                            <td className="px-4 py-3 text-slate-900">{g.label}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{g.enrollment}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{pct(g.promotion_rate)}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{pct(g.retention_rate)}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{pct(g.dropout_rate)}</td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={cn(
                                                        'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                                        statusStyle[g.status].cls,
                                                    )}
                                                >
                                                    <Icon name={statusStyle[g.status].icon} /> {statusStyle[g.status].label}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </>
            )}

            {metrics && tab === 'resources' && (
                <>
                    <h2 className="mb-4 text-xl font-bold text-slate-900">Resource Adequacy</h2>
                    <div className="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <StatCard compact label="Active Sections" value={metrics.resources.sections} tone="primary" />
                        <StatCard compact label="Learners per Section" value={metrics.resources.learners_per_section ?? 'n/a'} tone="blue" />
                        <StatCard compact label="Seat Fill Rate" value={pct(metrics.resources.fill_rate)} tone="green" />
                        <StatCard compact label="Sections ≥90% Full" value={metrics.resources.nearly_full} tone="red" />
                        <StatCard compact label="Sections Without Adviser" value={metrics.resources.without_adviser} tone="red" />
                        <StatCard compact label="Learners Not in a Section" value={metrics.resources.unassigned_learners} tone="purple" />
                        <StatCard compact label="Advisers" value={metrics.resources.advisers} tone="primary" />
                        <StatCard compact label="Total Seats" value={metrics.resources.capacity} tone="blue" />
                    </div>
                    <h2 className="mb-4 text-xl font-bold text-slate-900">Performance Trends</h2>
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <section className="panel p-6">
                            <h3 className="mb-4 font-semibold text-slate-900">Enrollment per School Year</h3>
                            <LineChart
                                label="Enrolled learners per school year"
                                labels={metrics.trends.map((t) => t.year)}
                                series={[{ label: 'Enrollment', data: metrics.trends.map((t) => t.enrollment) }]}
                            />
                        </section>
                        <section className="panel p-6">
                            <h3 className="mb-4 font-semibold text-slate-900">Dropout Rate per School Year</h3>
                            <LineChart
                                label="Dropout rate per school year"
                                suffix="%"
                                labels={metrics.trends.map((t) => t.year)}
                                series={[{ label: 'Dropout rate', data: metrics.trends.map((t) => t.dropout_rate) }]}
                            />
                        </section>
                    </div>
                </>
            )}

            {metrics && tab === 'insights' && (
                <section className="panel p-6">
                    <h2 className="mb-4 text-xl font-bold text-slate-900">Key Insights &amp; Recommendations</h2>
                    <ul className="space-y-3">
                        {metrics.insights.map((i, n) => (
                            <li key={n} className={cn('flex items-start gap-3 rounded-lg p-4 text-sm', statusStyle[i.tone].cls)}>
                                <Icon name={statusStyle[i.tone].icon} className="mt-px" />
                                <span>
                                    <span className="font-semibold">{statusStyle[i.tone].label}: </span>
                                    {i.text}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </AppLayout>
    );
}
