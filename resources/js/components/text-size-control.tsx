import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Three "A" buttons that make every page bigger, for staff who find small
 * text hard to read. The choice is saved to the account (and a cookie for
 * the login page), so it sticks on every computer they use.
 */
export function TextSizeControl({ className, tone = 'light' }: { className?: string; tone?: 'light' | 'dark' }) {
    const { textSize } = usePage<SharedData>().props;
    const [current, setCurrent] = useState(textSize.value);

    useEffect(() => setCurrent(textSize.value), [textSize.value]);

    const choose = (value: string, percent: number) => {
        setCurrent(value);
        document.documentElement.style.fontSize = `${percent}%`;
        router.put(route('text-size.update'), { text_size: value }, { preserveScroll: true, preserveState: true });
    };

    const glyph = ['text-sm', 'text-base', 'text-xl'];

    return (
        <div className={cn('flex items-center gap-2', className)} role="group" aria-label="Text size">
            <span className={cn('text-xs font-medium', tone === 'dark' ? 'text-green-100' : 'text-slate-500')}>Text size</span>
            <div
                className={cn(
                    'flex overflow-hidden rounded-lg border',
                    tone === 'dark' ? 'border-white/30 bg-white/10' : 'border-slate-300 bg-white',
                )}
            >
                {textSize.options.map((o, i) => (
                    <button
                        key={o.value}
                        type="button"
                        onClick={() => choose(o.value, o.percent)}
                        aria-pressed={current === o.value}
                        title={o.label}
                        aria-label={`${o.label} text`}
                        className={cn(
                            'flex h-9 w-10 items-center justify-center font-bold transition-colors',
                            i > 0 && (tone === 'dark' ? 'border-l border-white/30' : 'border-l border-slate-300'),
                            current === o.value
                                ? tone === 'dark'
                                    ? 'text-primary bg-white'
                                    : 'bg-primary text-white'
                                : tone === 'dark'
                                  ? 'text-white hover:bg-white/15'
                                  : 'text-slate-700 hover:bg-slate-100',
                        )}
                    >
                        <span className={glyph[i]}>A</span>
                    </button>
                ))}
            </div>
        </div>
    );
}
