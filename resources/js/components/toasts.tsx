import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Icon } from './icon';

/**
 * Top-right toasts like the legacy pages. Successes fade after a few
 * seconds; errors stay until dismissed so they are not missed.
 */
export function Toasts() {
    const { flash } = usePage<SharedData>().props;
    const [items, setItems] = useState<{ id: number; tone: 'success' | 'error'; text: string }[]>([]);

    useEffect(() => {
        const next: typeof items = [];
        if (flash.error) next.push({ id: Date.now() + 1, tone: 'error', text: flash.error });
        if (flash.success) next.push({ id: Date.now(), tone: 'success', text: flash.success });
        if (next.length) setItems((current) => [...next, ...current].slice(0, 3));
    }, [flash.success, flash.error]);

    useEffect(() => {
        const timers = items.filter((i) => i.tone === 'success').map((i) => setTimeout(() => setItems((c) => c.filter((x) => x.id !== i.id)), 6000));
        return () => timers.forEach(clearTimeout);
    }, [items]);

    return (
        <div className="pointer-events-none fixed top-5 right-5 left-5 z-[60] flex flex-col items-end gap-3" aria-live="polite">
            {items.map((t) => (
                <div
                    key={t.id}
                    role={t.tone === 'error' ? 'alert' : 'status'}
                    className={cn(
                        'pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-lg px-5 py-3 text-sm font-medium text-white shadow-lg',
                        t.tone === 'error' ? 'bg-red-600' : 'bg-primary',
                    )}
                >
                    <Icon name={t.tone === 'error' ? 'error' : 'check_circle'} className="mt-px" />
                    <span className="flex-1">{t.text}</span>
                    <button
                        type="button"
                        onClick={() => setItems((c) => c.filter((x) => x.id !== t.id))}
                        className="opacity-80 hover:opacity-100"
                        aria-label="Dismiss"
                    >
                        <Icon name="close" />
                    </button>
                </div>
            ))}
        </div>
    );
}
