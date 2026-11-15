import { cn } from '@/lib/utils';
import { Icon } from './icon';
import { type IconName } from './icons';

const tones = {
    green: 'text-emerald-600',
    orange: 'text-orange-500',
    indigo: 'text-indigo-500',
    red: 'text-red-600',
    slate: 'text-slate-500',
    primary: 'text-primary',
    blue: 'text-blue-600',
    purple: 'text-purple-600',
};

/**
 * Legacy stat tile. "large" is the dashboard version; "compact" is the
 * smaller tile used on the user-account and audit-log pages.
 */
export function StatCard({
    label,
    value,
    hint,
    tone = 'green',
    icon,
    compact = false,
}: {
    label: string;
    value: React.ReactNode;
    hint?: string;
    tone?: keyof typeof tones;
    icon?: IconName;
    compact?: boolean;
}) {
    if (compact) {
        return (
            <div className="panel p-4">
                <p className="text-xs text-slate-500">{label}</p>
                <p className={cn('text-2xl font-bold tabular-nums', tones[tone])}>{value}</p>
            </div>
        );
    }

    return (
        <div className="panel flex items-start justify-between gap-4 p-6">
            <div className="flex flex-col gap-2">
                <p className="text-base font-medium text-slate-700">{label}</p>
                <p className="text-3xl leading-tight font-bold text-slate-900 tabular-nums">{value}</p>
                {hint && <p className={cn('text-sm font-medium', tones[tone])}>{hint}</p>}
            </div>
            {icon && (
                <div className="text-primary flex size-12 items-center justify-center rounded-lg bg-green-50">
                    <Icon name={icon} />
                </div>
            )}
        </div>
    );
}
