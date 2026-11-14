import { cn } from '@/lib/utils';
import * as React from 'react';

/**
 * A styled native <select>. Native selects handle an empty "All" option and
 * phone keyboards better than a custom dropdown.
 */
export const NativeSelect = React.forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(({ className, ...props }, ref) => (
    <select
        ref={ref}
        className={cn(
            'border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
            className,
        )}
        {...props}
    />
));
NativeSelect.displayName = 'NativeSelect';
