import { useEffect, useRef, useState } from 'react';
import { Icon } from './icon';
import { TextSizeControl } from './text-size-control';

/**
 * The text-size choice as a small floating button in the bottom-right
 * corner of the sign-in page. Tapping it opens the three "A" buttons above
 * it; tapping outside or pressing Escape closes them again.
 */
export function TextSizeWidget() {
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        const onDown = (e: PointerEvent) => box.current && !box.current.contains(e.target as Node) && setOpen(false);
        document.addEventListener('keydown', onKey);
        document.addEventListener('pointerdown', onDown);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('pointerdown', onDown);
        };
    }, [open]);

    return (
        <div ref={box} className="fixed right-4 bottom-4 z-40 flex flex-col items-end gap-2 sm:right-6 sm:bottom-6">
            {open && (
                <div id="text-size-panel" className="rounded-xl border border-slate-200 bg-white p-3 shadow-xl">
                    <TextSizeControl />
                </div>
            )}
            <button
                type="button"
                aria-expanded={open}
                aria-controls="text-size-panel"
                onClick={() => setOpen((v) => !v)}
                className="text-primary focus-visible:ring-primary inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold shadow-lg transition-colors hover:bg-green-50 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                <Icon name="format_size" className="text-[1.3em]" />
                Text size
            </button>
        </div>
    );
}
