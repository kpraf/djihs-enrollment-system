import { cn } from '@/lib/utils';

const styles: Record<string, { cls: string; label: string }> = {
    enrolled: { cls: 'bg-green-100 text-green-800', label: 'Enrolled' },
    cancelled: { cls: 'bg-gray-100 text-gray-700', label: 'Cancelled' },
    active: { cls: 'bg-green-100 text-green-800', label: 'Active' },
    inactive: { cls: 'bg-red-100 text-red-800', label: 'Inactive' },
};

export function StatusBadge({ status }: { status: string }) {
    const s = styles[status] ?? { cls: 'bg-slate-100 text-slate-700', label: status };

    return <span className={cn('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold', s.cls)}>{s.label}</span>;
}
