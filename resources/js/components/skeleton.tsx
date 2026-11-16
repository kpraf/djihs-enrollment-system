import { cn } from '@/lib/utils';

/**
 * Grey placeholders shown where a block of the page is still loading, the
 * same size as what replaces them so nothing jumps. They pulse gently,
 * except for people who asked their device for less motion. Screen readers
 * hear "Loading <what>" once instead of a row of empty boxes.
 */
export function Bone({ className, style }: { className?: string; style?: React.CSSProperties }) {
    return <div aria-hidden className={cn('rounded-md bg-slate-200/80 motion-safe:animate-pulse', className)} style={style} />;
}

function Loading({ what, className, children }: { what: string; className?: string; children: React.ReactNode }) {
    return (
        <div role="status" className={className}>
            <span className="sr-only">Loading {what}…</span>
            {children}
        </div>
    );
}

/** A panel with a title line and a chart-sized block. */
export function ChartSkeleton({ what, height = 220, className }: { what: string; height?: number; className?: string }) {
    return (
        <Loading what={what} className={cn('panel p-5', className)}>
            <Bone className="mb-4 h-5 w-56" />
            <Bone className="w-full" style={{ height }} />
        </Loading>
    );
}

/** A table: a header row, then rows of bars in a few columns. */
export function TableSkeleton({ what, rows = 5, columns = 4, className }: { what: string; rows?: number; columns?: number; className?: string }) {
    return (
        <Loading what={what} className={cn('panel overflow-hidden', className)}>
            <div className="flex gap-6 bg-slate-50 px-5 py-3.5">
                {Array.from({ length: columns }, (_, i) => (
                    <Bone key={i} className={cn('h-3.5', i === 0 ? 'w-32' : 'w-16')} />
                ))}
            </div>
            <div className="divide-y divide-slate-100">
                {Array.from({ length: rows }, (_, r) => (
                    <div key={r} className="flex items-center gap-6 px-5 py-3.5">
                        {Array.from({ length: columns }, (_, i) => (
                            <Bone key={i} className={cn('h-4', i === 0 ? 'w-40' : 'w-12')} />
                        ))}
                    </div>
                ))}
            </div>
        </Loading>
    );
}

/** A row of stat tiles: label, big number, small note. */
export function TilesSkeleton({ what, count = 4, className }: { what: string; count?: number; className?: string }) {
    return (
        <Loading what={what} className={cn('grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4', className)}>
            {Array.from({ length: count }, (_, i) => (
                <div key={i} className="panel space-y-3 p-5">
                    <Bone className="h-4 w-28" />
                    <Bone className="h-8 w-20" />
                    <Bone className="h-3.5 w-36" />
                </div>
            ))}
        </Loading>
    );
}
