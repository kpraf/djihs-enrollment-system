import { cn } from '@/lib/utils';
import { type Paginated } from '@/types';
import { Link } from '@inertiajs/react';

export function Pagination<T>({ page }: { page: Paginated<T> }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav className="flex flex-wrap items-center justify-between gap-2 text-sm">
            <p className="text-muted-foreground">
                Showing {page.from} to {page.to} of {page.total}
            </p>
            <div className="flex flex-wrap gap-1">
                {page.links.map((link, i) => (
                    <Link
                        key={i}
                        href={link.url ?? '#'}
                        preserveScroll
                        preserveState
                        className={cn(
                            'rounded-md border px-3 py-1',
                            link.active && 'bg-primary text-primary-foreground border-primary',
                            !link.url && 'pointer-events-none opacity-40',
                        )}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ))}
            </div>
        </nav>
    );
}
