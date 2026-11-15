/**
 * Big black page title with a grey subtitle, actions on the right.
 */
export function PageHeader({ title, description, actions }: { title: React.ReactNode; description?: React.ReactNode; actions?: React.ReactNode }) {
    return (
        <div className="mb-6 flex flex-wrap items-start justify-between gap-4 sm:mb-8">
            <div className="flex min-w-0 flex-col gap-2">
                <h1 className="text-3xl leading-tight font-black tracking-tight text-slate-900 sm:text-4xl">{title}</h1>
                {description && <p className="text-base text-slate-500">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-3">{actions}</div>}
        </div>
    );
}
