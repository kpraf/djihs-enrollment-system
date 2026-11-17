import { cn } from '@/lib/utils';
import { type KeyboardEvent, useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Icon } from './icon';
import { type IconName } from './icons';

export interface ActionMenuItem {
    label: string;
    /** A second line saying what the item gives, e.g. "Excel · DepEd form". */
    hint?: string;
    icon: IconName;
    href: string;
    /** Opens in a new tab, e.g. a printable page. Files download in place. */
    newTab?: boolean;
}

/**
 * A button that opens a short list of links on tap or click, not hover, so it
 * works the same on a phone. The list floats above the page, so a table that
 * scrolls sideways can't cut it off.
 */
export function ActionMenu({
    label,
    icon,
    items,
    srSuffix,
    className,
}: {
    label: string;
    icon: IconName;
    items: ActionMenuItem[];
    /** Read out after the label, e.g. "for Integrity". */
    srSuffix?: string;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState<{ top?: number; bottom?: number; left: number } | null>(null);
    const button = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);
    const id = useId();

    const close = (refocus = true) => {
        setOpen(false);
        setPosition(null);
        if (refocus) button.current?.focus();
    };

    // Below the button, or above it when there is no room underneath; lined
    // up with the button's right edge, but never past either side of the screen.
    useLayoutEffect(() => {
        if (!open || !button.current || !menu.current) return;
        const rect = button.current.getBoundingClientRect();
        const width = menu.current.offsetWidth;
        const height = menu.current.offsetHeight;
        const left = Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8);
        const room = window.innerHeight - rect.bottom;
        setPosition(room < height + 12 && rect.top > room ? { bottom: window.innerHeight - rect.top + 4, left } : { top: rect.bottom + 4, left });
    }, [open]);

    // Hidden elements can't take focus, so move it in once the menu shows.
    useEffect(() => {
        if (open && position) menu.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
    }, [open, position]);

    useEffect(() => {
        if (!open) return;
        const outside = (e: PointerEvent) => {
            if (!menu.current?.contains(e.target as Node) && !button.current?.contains(e.target as Node)) close(false);
        };
        const away = () => close(false);
        document.addEventListener('pointerdown', outside);
        window.addEventListener('resize', away);
        window.addEventListener('scroll', away, true);
        return () => {
            document.removeEventListener('pointerdown', outside);
            window.removeEventListener('resize', away);
            window.removeEventListener('scroll', away, true);
        };
    }, [open]);

    const onMenuKey = (e: KeyboardEvent<HTMLDivElement>) => {
        const links = Array.from(menu.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);
        const at = links.indexOf(document.activeElement as HTMLElement);
        const go = (i: number) => links[(i + links.length) % links.length]?.focus();
        if (e.key === 'Escape') {
            e.preventDefault();
            close();
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            go(at + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            go(at - 1);
        } else if (e.key === 'Home') {
            e.preventDefault();
            go(0);
        } else if (e.key === 'End') {
            e.preventDefault();
            go(links.length - 1);
        } else if (e.key === 'Tab') {
            close(false);
        }
    };

    return (
        <>
            <button
                ref={button}
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? id : undefined}
                onClick={() => (open ? close() : setOpen(true))}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown' && !open) {
                        e.preventDefault();
                        setOpen(true);
                    }
                }}
                className={cn(
                    'hover:border-primary hover:text-primary inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium whitespace-nowrap text-slate-700 transition-colors hover:bg-green-50',
                    open && 'border-primary text-primary bg-green-50',
                    className,
                )}
            >
                <Icon name={icon} className="text-[1.1em]" />
                <span>{label}</span>
                {srSuffix && <span className="sr-only"> {srSuffix}</span>}
                <Icon name="keyboard_arrow_down" className={cn('text-[1.1em] transition-transform', open && 'rotate-180')} />
            </button>
            {open &&
                createPortal(
                    <div
                        ref={menu}
                        id={id}
                        role="menu"
                        aria-label={`${label}${srSuffix ? ` ${srSuffix}` : ''}`}
                        onKeyDown={onMenuKey}
                        style={{
                            top: position?.top,
                            bottom: position?.bottom,
                            left: position?.left ?? 0,
                            visibility: position ? 'visible' : 'hidden',
                        }}
                        className="fixed z-50 w-72 max-w-[calc(100vw-1rem)] rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl"
                    >
                        {items.map((item) => (
                            <a
                                key={item.href}
                                role="menuitem"
                                href={item.href}
                                target={item.newTab ? '_blank' : undefined}
                                rel={item.newTab ? 'noreferrer' : undefined}
                                onClick={() => close(false)}
                                className="flex items-start gap-3 rounded-lg px-3 py-2.5 text-left outline-none hover:bg-green-50 focus-visible:bg-green-50 focus-visible:ring-2 focus-visible:ring-green-700/30"
                            >
                                <Icon name={item.icon} className="text-primary mt-0.5 shrink-0 text-xl" />
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold text-slate-900">{item.label}</span>
                                    {item.hint && <span className="block text-xs text-slate-500">{item.hint}</span>}
                                </span>
                            </a>
                        ))}
                    </div>,
                    document.body,
                )}
        </>
    );
}
