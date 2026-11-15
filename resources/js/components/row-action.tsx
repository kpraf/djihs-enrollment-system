import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Icon } from './icon';
import { type IconName } from './icons';

interface RowActionProps {
    icon: IconName;
    /** The word people see, e.g. "Export SF1". */
    text: string;
    /** Read out after the text, e.g. the section's name, so "Export SF1 for Integrity". */
    srSuffix?: string;
    tone?: 'default' | 'danger';
    className?: string;
}

const style = (tone: RowActionProps['tone'], className?: string) =>
    cn(
        'inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium whitespace-nowrap text-slate-700 transition-colors disabled:cursor-not-allowed disabled:opacity-40',
        tone === 'danger'
            ? 'enabled:hover:border-red-200 enabled:hover:bg-red-50 enabled:hover:text-red-700 [&:is(a)]:hover:border-red-200 [&:is(a)]:hover:bg-red-50 [&:is(a)]:hover:text-red-700'
            : 'hover:border-primary hover:text-primary hover:bg-green-50',
        className,
    );

function Content({ icon, text, srSuffix }: RowActionProps) {
    return (
        <>
            <Icon name={icon} className="text-[1.1em]" />
            <span>{text}</span>
            {srSuffix && <span className="sr-only"> {srSuffix}</span>}
        </>
    );
}

/**
 * A table row action that says what it does: an icon and a word, not an icon
 * alone, so nobody has to hover to find out what "the little page" button is.
 */
export function RowActionButton({
    onClick,
    disabled,
    title,
    ...props
}: RowActionProps & { onClick: () => void; disabled?: boolean; title?: string }) {
    return (
        <button type="button" className={style(props.tone, props.className)} onClick={onClick} disabled={disabled} title={title}>
            <Content {...props} />
        </button>
    );
}

/** The same look for a link: an Inertia page, or a plain download with `download`. */
export function RowActionLink({ href, download, ...props }: RowActionProps & { href: string; download?: boolean }) {
    return download ? (
        <a href={href} className={style(props.tone, props.className)}>
            <Content {...props} />
        </a>
    ) : (
        <Link href={href} className={style(props.tone, props.className)}>
            <Content {...props} />
        </Link>
    );
}
