import { NativeSelect } from '@/components/native-select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type Option, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

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
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const number = new Intl.NumberFormat('en-PH');
const dateFormat = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric', weekday: 'short' });

export default function Dashboard({ schoolYears, selectedYear, monitoring, daily }: Props) {
    const { auth } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Welcome, {auth.user.first_name}</h1>
                        <p className="text-muted-foreground text-sm">
                            {selectedYear ? `Enrollment summary for school year ${selectedYear.name}` : 'No school year has been set up yet.'}
                        </p>
                    </div>
                    {schoolYears.length > 1 && (
                        <label className="grid gap-1 text-sm">
                            <span className="text-muted-foreground">School year</span>
                            <NativeSelect
                                className="w-52"
                                value={selectedYear?.id ?? ''}
                                onChange={(e) => router.get('/dashboard', { school_year: e.target.value }, { preserveState: true })}
                            >
                                {schoolYears.map((y) => (
                                    <option key={y.id} value={y.id}>
                                        {y.name}
                                        {y.is_active ? ' (current)' : ''}
                                    </option>
                                ))}
                            </NativeSelect>
                        </label>
                    )}
                </div>

                {monitoring && <Overview monitoring={monitoring} />}

                {monitoring?.grades.map((grade) => <GradeTable key={grade.grade_level} grade={grade} types={monitoring.learner_types} />)}

                {monitoring && monitoring.grades.length === 0 && (
                    <Card>
                        <CardContent className="text-muted-foreground py-10 text-center text-sm">
                            No learners have been encoded for this school year yet.
                        </CardContent>
                    </Card>
                )}

                {daily.length > 0 && <DailyTable rows={daily} grades={monitoring?.grades.map((g) => g.grade_level) ?? []} />}
            </div>
        </AppLayout>
    );
}

function Overview({ monitoring }: { monitoring: Monitoring }) {
    const stats: { label: string; value: number | string; hint: string }[] = [
        {
            label: 'Enrolled',
            value: monitoring.total.total,
            hint: `${number.format(monitoring.total.male)} male · ${number.format(monitoring.total.female)} female`,
        },
        ...monitoring.grades.map((g) => ({
            label: g.label,
            value: g.total.total,
            hint: `Academic ${g.tracks.academic.total} · TVL ${g.tracks.tvl.total}`,
        })),
        { label: 'Cancelled', value: monitoring.cancelled, hint: 'Not counted as enrolled' },
    ];

    if (monitoring.grade_11_retention !== null) {
        stats.push({
            label: 'Grade 10 completers who stayed',
            value: `${(monitoring.grade_11_retention * 100).toFixed(1)}%`,
            hint: `Grade 11 old students out of ${monitoring.grade_10_completers} completers`,
        });
    }

    return (
        <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-5">
            {stats.map((s) => (
                <Card key={s.label} className="p-4">
                    <p className="text-muted-foreground text-sm">{s.label}</p>
                    <p className="mt-1 text-2xl font-semibold tabular-nums sm:text-3xl">
                        {typeof s.value === 'number' ? number.format(s.value) : s.value}
                    </p>
                    <p className="text-muted-foreground mt-1 text-xs">{s.hint}</p>
                </Card>
            ))}
        </div>
    );
}

const th = 'px-3 py-2 text-right font-medium whitespace-nowrap';
const td = 'px-3 py-2 text-right tabular-nums';

function TallyCells({ tally, types }: { tally: Tally; types: Option[] }) {
    return (
        <>
            <td className={td}>{tally.male}</td>
            <td className={td}>{tally.female}</td>
            <td className={cn(td, 'font-semibold')}>{tally.total}</td>
            {types.map((t) => (
                <td key={t.value} className={cn(td, 'text-muted-foreground', tally.learner_types[t.value] > 0 && 'text-foreground')}>
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
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="border-b px-4 py-3">
                <CardTitle className="text-base">{grade.label}</CardTitle>
            </CardHeader>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th className="px-3 py-2 text-left font-medium">Strand</th>
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
                    <tbody className="divide-y">
                        {grade.strands.map((s) => (
                            <tr key={s.code}>
                                <td className="px-3 py-2 font-medium whitespace-nowrap" title={s.name}>
                                    {s.code}
                                </td>
                                <TallyCells tally={s} types={types} />
                            </tr>
                        ))}
                    </tbody>
                    <tfoot className="divide-y border-t">
                        <tr className="bg-muted/30">
                            <td className="px-3 py-2 whitespace-nowrap">Academic track</td>
                            <TallyCells tally={grade.tracks.academic} types={types} />
                        </tr>
                        <tr className="bg-muted/30">
                            <td className="px-3 py-2 whitespace-nowrap">TVL track</td>
                            <TallyCells tally={grade.tracks.tvl} types={types} />
                        </tr>
                        <tr className="bg-muted/60 font-semibold">
                            <td className="px-3 py-2">Total</td>
                            <TallyCells tally={grade.total} types={types} />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>
    );
}

function DailyTable({ rows, grades }: { rows: DailyRow[]; grades: number[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="border-b px-4 py-3">
                <CardTitle className="text-base">Daily encoding</CardTitle>
                <p className="text-muted-foreground text-sm">Learners encoded each day, including ones later cancelled.</p>
            </CardHeader>
            <div className="max-h-[28rem] overflow-auto">
                <table className="w-full text-sm">
                    <thead className="bg-muted text-muted-foreground sticky top-0">
                        <tr>
                            <th className="px-3 py-2 text-left font-medium">Date</th>
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
                    <tbody className="divide-y">
                        {rows.map((r) => (
                            <tr key={r.date ?? 'none'}>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {r.date ? (
                                        dateFormat.format(new Date(r.date + 'T00:00:00'))
                                    ) : (
                                        <span className="text-muted-foreground">No date recorded</span>
                                    )}
                                </td>
                                {grades.map((g) => (
                                    <td key={g} className={td}>
                                        {r.grades[g]?.total ?? 0}
                                    </td>
                                ))}
                                <td className={td}>{r.male}</td>
                                <td className={td}>{r.female}</td>
                                <td className={cn(td, 'font-semibold')}>{r.total}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}
