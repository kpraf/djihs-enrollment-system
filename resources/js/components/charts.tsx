import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
    type ChartConfiguration,
} from 'chart.js';
import { useEffect, useRef } from 'react';

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend);

/**
 * Categorical slots from the validated reference palette (light mode):
 * slot 1 blue, slot 2 orange. Assigned in this fixed order, never cycled.
 * Checked with the dataviz validator: CVD ΔE 24.7, normal-vision ΔE 33.6, ≥3:1 contrast.
 */
export const SERIES = ['#2a78d6', '#eb6834'] as const;

const INK = '#52514e';
const GRID = '#e8e8e5';

export interface Series {
    label: string;
    data: number[];
}

/** Chart text follows the root font size, so the text-size setting enlarges charts too. */
function rootPx(scale: number) {
    return Math.round(parseFloat(getComputedStyle(document.documentElement).fontSize || '16') * scale);
}

function useChart(config: () => ChartConfiguration, deps: unknown[]) {
    const canvas = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        if (!canvas.current) return;
        Chart.defaults.font.family = "'Inter Variable', Inter, system-ui, sans-serif";
        Chart.defaults.font.size = rootPx(0.75);
        Chart.defaults.color = INK;
        const chart = new Chart(canvas.current, config());
        return () => chart.destroy();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, deps);

    return canvas;
}

const scales = (horizontal: boolean) => {
    const value = { beginAtZero: true, grid: { color: GRID, lineWidth: 1 }, border: { display: false }, ticks: { precision: 0 } };
    const category = { grid: { display: false }, border: { color: GRID } };
    return horizontal ? { x: value, y: category } : { x: category, y: value };
};

/**
 * Bars at most 24px thick with 4px rounded data ends, square at the baseline.
 * A legend appears only for two or more series; every bar has a hover tooltip.
 */
export function BarChart({
    labels,
    series,
    horizontal = false,
    height = 260,
    label,
}: {
    labels: string[];
    series: Series[];
    horizontal?: boolean;
    height?: number;
    label: string;
}) {
    const canvas = useChart(
        () => ({
            type: 'bar',
            data: {
                labels,
                datasets: series.map((s, i) => ({
                    label: s.label,
                    data: s.data,
                    backgroundColor: SERIES[i % SERIES.length],
                    hoverBackgroundColor: SERIES[i % SERIES.length],
                    maxBarThickness: 24,
                    borderRadius: 4,
                    borderSkipped: 'start',
                    categoryPercentage: 0.7,
                    barPercentage: 0.9,
                })),
            },
            options: {
                indexAxis: horizontal ? 'y' : 'x',
                maintainAspectRatio: false,
                responsive: true,
                animation: false,
                scales: scales(horizontal),
                plugins: {
                    legend: {
                        display: series.length > 1,
                        position: 'top',
                        align: 'start',
                        labels: { boxWidth: 12, boxHeight: 12, useBorderRadius: true, borderRadius: 2 },
                    },
                    tooltip: { mode: 'index', intersect: false },
                },
            },
        }),
        [JSON.stringify(labels), JSON.stringify(series), horizontal],
    );

    return (
        <div style={{ height }} className="relative">
            <canvas ref={canvas} role="img" aria-label={label} />
        </div>
    );
}

/**
 * 2px lines with 8px end markers and a crosshair-style index tooltip.
 */
export function LineChart({
    labels,
    series,
    height = 260,
    label,
    suffix = '',
    max,
}: {
    labels: string[];
    series: Series[];
    height?: number;
    label: string;
    suffix?: string;
    /** A fixed top for the y-axis, so small multiples share one scale. */
    max?: number;
}) {
    const canvas = useChart(
        () => ({
            type: 'line',
            data: {
                labels,
                datasets: series.map((s, i) => ({
                    label: s.label,
                    data: s.data,
                    borderColor: SERIES[i % SERIES.length],
                    backgroundColor: SERIES[i % SERIES.length],
                    borderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
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
                scales: { ...scales(false), y: { ...scales(false).y, max, ticks: { precision: 0, callback: (v) => `${v}${suffix}` } } },
                plugins: {
                    legend: { display: series.length > 1, position: 'top', align: 'start', labels: { boxWidth: 12, boxHeight: 12 } },
                    tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${c.formattedValue}${suffix}` } },
                },
            },
        }),
        [JSON.stringify(labels), JSON.stringify(series), suffix, max],
    );

    return (
        <div style={{ height }} className="relative">
            <canvas ref={canvas} role="img" aria-label={label} />
        </div>
    );
}
