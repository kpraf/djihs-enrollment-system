import { cn } from '@/lib/utils';
import { forwardRef, useId } from 'react';

export function InputError({ message, className }: { message?: string; className?: string }) {
    if (!message) return null;

    return (
        <p className={cn('mt-1.5 text-sm text-red-600', className)} role="alert">
            {message}
        </p>
    );
}

/**
 * Label above, control, error below, like the legacy form. Pass the control
 * as a render function to get the generated id and error state.
 */
export function Field({
    label,
    required,
    error,
    hint,
    className,
    small,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    hint?: string;
    className?: string;
    small?: boolean;
    children: (props: { id: string; 'aria-invalid': boolean; 'aria-describedby'?: string }) => React.ReactNode;
}) {
    const id = useId();
    const hintId = hint ? `${id}-hint` : undefined;

    return (
        <div className={cn('flex min-w-0 flex-col', className)}>
            <label htmlFor={id} className={small ? 'pb-1 text-xs text-slate-600' : 'field-label'}>
                {label}
                {required && <span className="text-red-500"> *</span>}
            </label>
            {children({ id, 'aria-invalid': Boolean(error), 'aria-describedby': hintId })}
            {hint && !error && (
                <p id={hintId} className="mt-1.5 text-xs text-slate-500">
                    {hint}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}

export const TextInput = forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(({ className, ...props }, ref) => (
    <input ref={ref} className={cn('field-input', className)} {...props} />
));
TextInput.displayName = 'TextInput';

export const SelectInput = forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(({ className, ...props }, ref) => (
    <select ref={ref} className={cn('field-input pr-10', className)} {...props} />
));
SelectInput.displayName = 'SelectInput';

/**
 * The legacy "Yes / No" radio pair.
 */
export function YesNo({
    label,
    name,
    value,
    onChange,
    error,
}: {
    label: string;
    name: string;
    value: boolean;
    onChange: (v: boolean) => void;
    error?: string;
}) {
    return (
        <fieldset className="flex flex-col">
            <legend className="field-label">{label}</legend>
            <div className="flex items-center gap-6">
                {[true, false].map((v) => (
                    <label key={String(v)} className="flex items-center gap-2 text-base text-slate-700">
                        <input type="radio" className="radio" name={name} checked={value === v} onChange={() => onChange(v)} />
                        {v ? 'Yes' : 'No'}
                    </label>
                ))}
            </div>
            <InputError message={error} />
        </fieldset>
    );
}
