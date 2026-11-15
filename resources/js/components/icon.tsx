import { cn } from '@/lib/utils';
import { icons, type IconName } from './icons';

/**
 * A Material Symbol drawn inline. Sized in em, so it follows the text size
 * around it and the user's text-size setting.
 */
export function Icon({ name, className, label }: { name: IconName; className?: string; label?: string }) {
    const svg = icons[name].replace('<svg ', '<svg fill="currentColor" width="1em" height="1em" ');

    return (
        <span
            className={cn('inline-flex shrink-0 items-center justify-center text-[1.25em] leading-none', className)}
            role={label ? 'img' : undefined}
            aria-label={label}
            aria-hidden={label ? undefined : true}
            dangerouslySetInnerHTML={{ __html: svg }}
        />
    );
}
