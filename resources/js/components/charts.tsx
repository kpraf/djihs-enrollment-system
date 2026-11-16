import { cn } from '@/lib/utils';
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    Filler,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
    type ChartConfiguration,
    type Plugin,
} from 'chart.js';
import { useEffect, useRef, useState } from 'react';

Chart.register(
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    DoughnutController,
    Filler,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
);

/**
 * Two series colors: school green, then blue, always in this order.
 * Checked with the dataviz palette validator on the white panel surface:
 * inside the lightness band, above the chroma floor, ≥3:1 contrast, and
 * colorblind-safe (protan/deutan ΔE 22, normal-vision ΔE 23). Green with
 * orange failed (ΔE 3), so orange is never paired with the green.
 */
export const SERIES = ['#23864a', '#2a78d6'] as const;
/** The "before" or "everything else" color when one series is the point. */
export const CONTEXT = '#c3c2b7';
/** The unfilled part of a meter: a light step of the same green. */
const TRACK = '#dcefe1';

const INK = '#0b0b0b';
const INK_SECONDARY = '#52514e';
const GRID = '#e8e8e5';
const FONT = "'Inter Variable', Inter, system-ui, sans-serif";

const number = new Intl.NumberFormat('en-PH');
export const formatNumber = (n: number) => number.format(n);
export const share = (n: number, of: number) => (of ? `${Math.round((n / of) * 1000) / 10}%` : '—');

export interface Series {
    label: string;
    data: number[];
    /** Overrides the series color, e.g. CONTEXT for last year's bars. */
    color?: string;
}

/** Chart text follows the root font size, so the text-size setting enlarges charts too. */
function rootPx(scale: number) {
    return Math.round(parseFloat(getComputedStyle(document.documentElement).fontSize || '16') * scale);
}

function useChart(config: () => ChartConfiguration, deps: unknown[], instance?: React.MutableRefObject<Chart | null>) {
    const canvas = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        if (!canvas.current) return;
        Chart.defaults.font.family = FONT;
        Chart.defaults.font.size = rootPx(0.75);
        Chart.defaults.color = INK_SECONDARY;
        const chart = new Chart(canvas.current, config());
        if (instance) instance.current = chart;
        return () => {
            if (instance) instance.current = null;
            chart.destroy();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, deps);

    return canvas;
}

const tooltip = {
    backgroundColor: '#ffffff',
    titleColor: INK_SECONDARY,
    bodyColor: INK,
    borderColor: GRID,
    borderWidth: 1,
    padding: 10,
    boxPadding: 4,
    usePointStyle: true,
    titleFont: { weight: 'normal' as const },
    bodyFont: { weight: 'bold' as const },
};

const scales = (horizontal: boolean) => {
    const value = {
        beginAtZero: true,
        grid: { color: GRID, lineWidth: 1 },
        border: { display: false },
        ticks: { precision: 0, callback: (v: string | number) => formatNumber(Number(v)) },
    };
    const category = { grid: { display: false }, border: { color: GRID } };
    return horizontal ? { x: value, y: category } : { x: category, y: value };
};

/** Prints each bar's value just past its end, in ink, never in the bar color. */
const barValues: Plugin<'bar'> = {
    id: 'barValues',
    afterDatasetsDraw(chart, _args, options: { enabled?: boolean }) {
        if (!options?.enabled) return;
        const horizontal = chart.options.indexAxis === 'y';
        const { ctx } = chart;
        ctx.save();
        ctx.font = `600 ${rootPx(0.75)}px ${FONT}`;
        ctx.fillStyle = INK_SECONDARY;
        chart.data.datasets.forEach((dataset, d) => {
            const meta = chart.getDatasetMeta(d);
            if (meta.hidden) return;
            meta.data.forEach((bar, i) => {
                const value = Number(dataset.data[i] ?? 0);
                if (!value) return;
                const { x, y } = bar.getProps(['x', 'y'], true) as { x: number; y: number };
                if (horizontal) {
                    ctx.textAlign = 'left';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(formatNumber(value), x + 6, y);
                } else {
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(formatNumber(value), x, y - 4);
                }
            });
        });
        ctx.restore();
    },
};

function SeriesLegend({ items }: { items: { label: string; color: string; kind: 'box' | 'line' }[] }) {
    return (
        <ul className="mb-3 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600">
            {items.map((i) => (
                <li key={i.label} className="flex items-center gap-2">
                    <span
                        aria-hidden
                        className={i.kind === 'box' ? 'size-3 rounded-sm' : 'h-0.5 w-4 rounded-full'}
                        style={{ backgroundColor: i.color }}
                    />
                    {i.label}
                </li>
            ))}
        </ul>
    );
}

/**
 * Bars at most 24px thick with 4px rounded data ends, square at the
 * baseline, and a 2px white gap between neighbours. A legend appears for two
 * or more series; every bar has a hover tooltip; `values` prints each bar's
 * number at its end.
 */
export function BarChart({
    labels,
    series,
    horizontal = false,
    height,
    label,
    values = false,
}: {
    labels: string[];
    series: Series[];
    horizontal?: boolean;
    height?: number;
    label: string;
    values?: boolean;
}) {
    // Horizontal bars get a fixed band per category, so long lists grow
    // instead of squashing.
    const band = series.length > 1 ? 40 : 30;
    const h = height ?? (horizontal ? Math.max(120, labels.length * band + 32) : 260);
    const colors = series.map((s, i) => s.color ?? SERIES[i % SERIES.length]);

    const canvas = useChart(
        () => ({
            type: 'bar',
            data: {
                labels,
                datasets: series.map((s, i) => ({
                    label: s.label,
                    data: s.data,
                    backgroundColor: colors[i],
                    hoverBackgroundColor: colors[i],
                    borderColor: '#ffffff',
                    borderWidth: 1,
                    maxBarThickness: 24,
                    borderRadius: 4,
                    borderSkipped: 'start',
                    categoryPercentage: 0.75,
                    barPercentage: 0.9,
                })),
            },
            options: {
                indexAxis: horizontal ? 'y' : 'x',
                maintainAspectRatio: false,
                responsive: true,
                animation: false,
                layout: { padding: values ? (horizontal ? { right: 44 } : { top: 20 }) : 0 },
                scales: scales(horizontal),
                plugins: {
                    legend: { display: false },
                    tooltip: { ...tooltip, mode: 'index', intersect: false },
                    barValues: { enabled: values },
                },
            },
            plugins: [barValues as Plugin],
        }),
        [JSON.stringify(labels), JSON.stringify(series), horizontal, values, h],
    );

    return (
        <div>
            {series.length > 1 && <SeriesLegend items={series.map((s, i) => ({ label: s.label, color: colors[i], kind: 'box' }))} />}
            <div style={{ height: h }} className="relative">
                <canvas ref={canvas} role="img" aria-label={label} />
            </div>
        </div>
    );
}

/**
 * 2px lines with 8px end markers ringed in white, and a crosshair-style
 * tooltip listing every series at that point. A single series gets a light
 * wash under the line.
 */
export function LineChart({
    labels,
    series,
    height = 260,
    label,
    suffix = '',
    max,
    points = true,
}: {
    labels: string[];
    series: Series[];
    height?: number;
    label: string;
    suffix?: string;
    /** A fixed top for the y-axis, so small multiples share one scale. */
    max?: number;
    /** Markers on every point; off for long daily series. */
    points?: boolean;
}) {
    const colors = series.map((s, i) => s.color ?? SERIES[i % SERIES.length]);
    const canvas = useChart(
        () => ({
            type: 'line',
            data: {
                labels,
                datasets: series.map((s, i) => ({
                    label: s.label,
                    data: s.data,
                    borderColor: colors[i],
                    backgroundColor: series.length === 1 ? `${colors[i]}1a` : colors[i],
                    pointBackgroundColor: colors[i],
                    fill: series.length === 1 ? 'origin' : false,
                    borderWidth: 2,
                    borderCapStyle: 'round',
                    borderJoinStyle: 'round',
                    pointRadius: points ? 4 : 0,
                    pointHoverRadius: 6,
                    pointHitRadius: 12,
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    tension: 0,
                })),
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    ...scales(false),
                    x: { ...scales(false).x, ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 12 } },
                    y: { ...scales(false).y, max, ticks: { precision: 0, callback: (v) => `${formatNumber(Number(v))}${suffix}` } },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { ...tooltip, callbacks: { label: (c) => `${c.dataset.label}: ${c.formattedValue}${suffix}` } },
                },
            },
        }),
        [JSON.stringify(labels), JSON.stringify(series), suffix, max, points],
    );

    return (
        <div>
            {series.length > 1 && <SeriesLegend items={series.map((s, i) => ({ label: s.label, color: colors[i], kind: 'line' }))} />}
            <div style={{ height }} className="relative">
                <canvas ref={canvas} role="img" aria-label={label} />
            </div>
        </div>
    );
}

/** Writes the total in the hole of a donut. */
const donutCenter: Plugin<'doughnut'> = {
    id: 'donutCenter',
    afterDraw(chart, _args, options: { value?: string; caption?: string }) {
        if (!options?.value) return;
        const { ctx, chartArea } = chart;
        const x = (chartArea.left + chartArea.right) / 2;
        const y = (chartArea.top + chartArea.bottom) / 2;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.fillStyle = INK;
        ctx.font = `700 ${rootPx(1.375)}px ${FONT}`;
        ctx.textBaseline = 'alphabetic';
        ctx.fillText(options.value, x, y + rootPx(0.25));
        if (options.caption) {
            ctx.fillStyle = INK_SECONDARY;
            ctx.font = `${rootPx(0.75)}px ${FONT}`;
            ctx.textBaseline = 'top';
            ctx.fillText(options.caption, x, y + rootPx(0.55));
        }
        ctx.restore();
    },
};

/**
 * A donut for one whole split into two parts, read at a glance: the total
 * in the middle, a 2px white gap between the parts, and a legend beside it
 * with each part's number and share. Never used to compare close values;
 * those get bars.
 */
export function DonutChart({ title, parts }: { title: string; parts: { label: string; value: number }[] }) {
    const total = parts.reduce((n, p) => n + p.value, 0);
    const colors = parts.map((_, i) => SERIES[i % SERIES.length]);
    const description = `${title}: ${parts.map((p) => `${p.label} ${formatNumber(p.value)} (${share(p.value, total)})`).join(', ')}`;
    // The middle names the first part's share; the total is already in the tiles above.
    const lead = parts[0];
    // A donut this small has no room for a tooltip, and the legend beside it
    // already lists every number. Pointing at a slice highlights its legend
    // row instead, and pointing at (or tabbing to) a row lifts its slice.
    const [active, setActive] = useState<number | null>(null);
    const chart = useRef<Chart | null>(null);
    const highlight = (i: number | null) => {
        setActive(i);
        const c = chart.current;
        if (!c || !total) return;
        c.setActiveElements(i === null ? [] : [{ datasetIndex: 0, index: i }]);
        c.update();
    };

    const canvas = useChart(
        () => ({
            type: 'doughnut',
            data: {
                labels: parts.map((p) => p.label),
                datasets: [
                    {
                        data: total ? parts.map((p) => p.value) : [1],
                        backgroundColor: total ? colors : [GRID],
                        hoverBackgroundColor: total ? colors : [GRID],
                        borderColor: '#ffffff',
                        borderWidth: total ? 2 : 0,
                        hoverOffset: 4,
                    },
                ],
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                animation: false,
                cutout: '70%',
                layout: { padding: 4 },
                onHover: (_event, elements) => setActive(total && elements.length ? elements[0].index : null),
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false },
                    donutCenter: total ? { value: `${Math.round((lead.value / total) * 100)}%`, caption: lead.label.toLowerCase() } : {},
                },
            },
            plugins: [donutCenter as Plugin],
        }),
        [JSON.stringify(parts)],
        chart,
    );

    return (
        <figure className="panel p-5" onMouseLeave={() => highlight(null)}>
            <figcaption className="mb-3 text-sm font-medium text-slate-600">{title}</figcaption>
            <div className="flex items-center gap-5">
                <div className="relative size-28 shrink-0">
                    <canvas ref={canvas} role="img" aria-label={description} />
                </div>
                <ul className="min-w-0 flex-1 space-y-1" onMouseLeave={() => highlight(null)}>
                    {parts.map((p, i) => (
                        <li
                            key={p.label}
                            tabIndex={0}
                            onMouseEnter={() => highlight(i)}
                            onFocus={() => highlight(i)}
                            onBlur={() => highlight(null)}
                            className={cn(
                                'focus-visible:ring-primary -mx-2 flex items-start gap-2 rounded-md px-2 py-1 text-sm transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                active === i && 'bg-slate-100',
                                active !== null && active !== i && 'opacity-50',
                            )}
                        >
                            <span aria-hidden className="mt-1.5 size-2.5 shrink-0 rounded-sm" style={{ backgroundColor: colors[i] }} />
                            <span className="min-w-0 flex-1 text-slate-700">
                                {p.label}
                                <span className="block text-slate-500">
                                    <span className="font-semibold text-slate-900">{formatNumber(p.value)}</span> · {share(p.value, total)}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </figure>
    );
}

/**
 * One value against its whole or limit: a name and figure above a bar
 * whose unfilled track is a light step of the same green.
 */
export function Meter({ label, value, of, note }: { label: string; value: number; of: number; note?: string }) {
    const pct = of ? Math.min(100, (value / of) * 100) : 0;
    return (
        <div>
            <div className="mb-1.5 flex items-baseline justify-between gap-3 text-sm">
                <span className="text-slate-700">{label}</span>
                <span className="shrink-0 text-slate-500">
                    <span className="font-semibold text-slate-900">{formatNumber(value)}</span> of {formatNumber(of)}
                    <span className="ml-2 font-semibold text-slate-900">{share(value, of)}</span>
                </span>
            </div>
            <div
                className="h-2 overflow-hidden rounded-full"
                style={{ backgroundColor: TRACK }}
                role="meter"
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={of}
                aria-valuenow={value}
                aria-valuetext={`${formatNumber(value)} of ${formatNumber(of)} (${share(value, of)})`}
            >
                <div className="h-full rounded-full" style={{ width: `${pct}%`, backgroundColor: SERIES[0] }} />
            </div>
            {note && <p className="mt-1 text-xs text-slate-500">{note}</p>}
        </div>
    );
}

/**
 * The exact numbers behind a chart, one click away: for anyone who needs
 * the figures, for printing, and for screen readers.
 */
export function TableToggle({ children, label = 'Show as table', className }: { children: React.ReactNode; label?: string; className?: string }) {
    const [open, setOpen] = useState(false);
    return (
        <details className={cn('group mt-3', className)} open={open} onToggle={(e) => setOpen((e.target as HTMLDetailsElement).open)}>
            <summary className="text-primary inline-flex cursor-pointer list-none items-center gap-1 text-sm font-semibold hover:underline print:hidden">
                <span aria-hidden className="transition-transform group-open:rotate-90">
                    ›
                </span>
                {open ? 'Hide table' : label}
            </summary>
            {open && <div className="mt-3">{children}</div>}
        </details>
    );
}
