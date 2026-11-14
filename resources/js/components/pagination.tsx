import { type Paginated } from '@/types';
import { Link } from '@inertiajs/react';

/**
 * "Showing 1 to 25 of 343 results" with Previous and Next, as on the legacy tables.
 */
export function Pagination<T>({ page }: { page: Paginated<T> }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 sm:px-6">
            <p className="text-sm text-slate-700">
                Showing <span className="font-medium">{page.from ?? 0}</span> to <span className="font-medium">{page.to ?? 0}</span> of{' '}
                <span className="font-medium">{page.total}</span> results
            </p>
            <div className="flex gap-3">
                {page.prev_page_url ? (
                    <Link href={page.prev_page_url} preserveScroll preserveState className="btn-outline">
                        Previous
                    </Link>
                ) : (
                    <span className="btn-outline pointer-events-none opacity-50">Previous</span>
                )}
                {page.next_page_url ? (
                    <Link href={page.next_page_url} preserveScroll preserveState className="btn-outline">
                        Next
                    </Link>
                ) : (
                    <span className="btn-outline pointer-events-none opacity-50">Next</span>
                )}
            </div>
        </div>
    );
}
