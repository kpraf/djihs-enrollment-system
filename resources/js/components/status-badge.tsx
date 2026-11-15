import { cn } from '@/lib/utils';

// Same colors as the legacy getStatusBadge().
const styles: Record<string, { cls: string; label: string }> = {
    pending: { cls: 'bg-yellow-100 text-yellow-800', label: 'Pending' },
    enrolled: { cls: 'bg-green-100 text-green-800', label: 'Enrolled' },
    rejected: { cls: 'bg-orange-100 text-orange-800', label: 'Rejected' },
    cancelled: { cls: 'bg-gray-100 text-gray-700', label: 'Cancelled' },
    dropped: { cls: 'bg-red-100 text-red-800', label: 'Dropout' },
    transferred_out: { cls: 'bg-purple-100 text-purple-800', label: 'Transferred Out' },
    active: { cls: 'bg-green-100 text-green-800', label: 'Active' },
    inactive: { cls: 'bg-red-100 text-red-800', label: 'Inactive' },
    approved: { cls: 'bg-green-100 text-green-800', label: 'Approved' },
    archived: { cls: 'bg-gray-100 text-gray-700', label: 'Archived' },
};

export function StatusBadge({ status }: { status: string }) {
    const s = styles[status] ?? { cls: 'bg-slate-100 text-slate-700', label: status };

    return (
        <span className={cn('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap', s.cls)}>{s.label}</span>
    );
}
