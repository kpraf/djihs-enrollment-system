import { cn } from '@/lib/utils';

/**
 * A small turning ring, sized to the text around it. For people who asked
 * their device for less motion it stays still and gently pulses instead.
 * Purely visual: the button or message next to it says what is happening.
 */
export function Spinner({ className }: { className?: string }) {
    return (
        <svg
            aria-hidden
            viewBox="0 0 24 24"
            fill="none"
            className={cn('size-[1.1em] shrink-0 motion-safe:animate-spin motion-reduce:animate-pulse', className)}
        >
            <circle cx="12" cy="12" r="9.5" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
            <path d="M21.5 12a9.5 9.5 0 0 0-9.5-9.5" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
        </svg>
    );
}

/**
 * The contents of a button that can be busy: its usual label, or a spinner
 * and a short "…ing" message while the request runs.
 */
export function BusyLabel({ busy, busyText, children }: { busy: boolean; busyText: string; children: React.ReactNode }) {
    return busy ? (
        <>
            <Spinner />
            <span>{busyText}</span>
        </>
    ) : (
        <>{children}</>
    );
}
