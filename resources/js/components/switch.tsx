import { cn } from '@/lib/utils';

interface SwitchProps {
    checked: boolean;
    onChange: () => void;
    /** Spoken name, e.g. "HUMSS offered". */
    label: string;
    onText?: string;
    offText?: string;
    disabled?: boolean;
}

/**
 * An on/off switch that reads at a glance: a green track with the word "On",
 * or a grey track with "Off". Screen readers hear it as a switch.
 */
export function Switch({ checked, onChange, label, onText = 'On', offText = 'Off', disabled }: SwitchProps) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={onChange}
            className="group focus-visible:ring-primary inline-flex items-center gap-2 rounded-full focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-50"
        >
            <span
                aria-hidden
                className={cn(
                    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                    checked ? 'bg-primary' : 'bg-slate-300 group-hover:bg-slate-400',
                )}
            >
                <span
                    className={cn(
                        'inline-block size-5 rounded-full bg-white shadow transition-transform',
                        checked ? 'translate-x-[22px]' : 'translate-x-0.5',
                    )}
                />
            </span>
            <span aria-hidden className={cn('w-16 text-left text-sm font-semibold', checked ? 'text-primary' : 'text-slate-500')}>
                {checked ? onText : offText}
            </span>
        </button>
    );
}
