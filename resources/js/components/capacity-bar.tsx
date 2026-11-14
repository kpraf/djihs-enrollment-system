import { cn } from '@/lib/utils';

/**
 * Seats used out of capacity, as a thin bar with the numbers beside it.
 */
export function CapacityBar({ filled, capacity }: { filled: number; capacity: number }) {
    const pct = capacity ? Math.min(100, Math.round((filled / capacity) * 100)) : 0;

    return (
        <div className="flex min-w-[8rem] items-center gap-2">
            <div
                className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"
                role="meter"
                aria-valuenow={filled}
                aria-valuemin={0}
                aria-valuemax={capacity}
                aria-label="Seats filled"
            >
                <div
                    className={cn('h-full rounded-full', pct >= 100 ? 'bg-red-500' : pct >= 90 ? 'bg-amber-500' : 'bg-primary')}
                    style={{ width: `${pct}%` }}
                />
            </div>
            <span className="text-xs whitespace-nowrap text-slate-600 tabular-nums">
                {filled}/{capacity}
            </span>
        </div>
    );
}
