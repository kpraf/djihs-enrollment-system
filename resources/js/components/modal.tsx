import { cn } from '@/lib/utils';
import { useEffect, useId, useRef } from 'react';
import { createPortal } from 'react-dom';
import { Icon } from './icon';

interface ModalProps {
    open: boolean;
    onClose: () => void;
    title?: string;
    children: React.ReactNode;
    footer?: React.ReactNode;
    size?: 'sm' | 'md' | 'lg' | 'xl';
    /** Hide the header and close button, for icon-style confirmations. */
    bare?: boolean;
}

const widths = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-5xl' };

/**
 * Centered dialog on a dimmed backdrop, like the legacy modals. Closes on
 * Escape and backdrop click, and keeps keyboard focus inside while open.
 */
export function Modal({ open, onClose, title, children, footer, size = 'md', bare = false }: ModalProps) {
    const titleId = useId();
    const panel = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;

        const previous = document.activeElement as HTMLElement | null;
        const focusable = () =>
            panel.current?.querySelectorAll<HTMLElement>('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        (panel.current?.querySelector<HTMLElement>('[data-autofocus]') ?? focusable()?.[0])?.focus();

        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
            if (e.key === 'Tab') {
                const items = focusable();
                if (!items?.length) return;
                const first = items[0];
                const last = items[items.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        };

        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
            previous?.focus();
        };
    }, [open, onClose]);

    if (!open) return null;

    return createPortal(
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm"
            onMouseDown={(e) => e.target === e.currentTarget && onClose()}
        >
            <div
                ref={panel}
                role="dialog"
                aria-modal="true"
                aria-labelledby={title ? titleId : undefined}
                className={cn('flex max-h-[90dvh] w-full flex-col overflow-hidden rounded-xl bg-white shadow-2xl', widths[size])}
            >
                {!bare && title && (
                    <div className="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4">
                        <h2 id={titleId} className="text-xl font-bold text-slate-900">
                            {title}
                        </h2>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                            aria-label="Close"
                        >
                            <Icon name="close" />
                        </button>
                    </div>
                )}
                <div className="overflow-y-auto p-6">{children}</div>
                {footer && <div className="flex flex-col-reverse gap-3 border-t border-slate-200 px-6 py-4 sm:flex-row sm:justify-end">{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}
