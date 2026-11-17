import { cn } from '@/lib/utils';
import { useState } from 'react';
import { Icon } from './icon';

export type GuideNeed = 'required' | 'senior_high' | 'optional' | 'automatic';

export interface GuideColumn {
    column: string;
    header: string;
    need: GuideNeed;
    enter: string;
    example: string;
    avoid: string;
}

interface DoDont {
    do: string[];
    dont: string[];
}

export interface ImportGuideData {
    columns: GuideColumn[];
    /** The letter of the ENROLLMENTSTATUS column, e.g. "AL". */
    status_column: string;
    status: DoDont;
    general: DoDont;
}

const NEED: Record<GuideNeed, { label: string; className: string }> = {
    required: { label: 'Required', className: 'bg-red-50 text-red-700 ring-red-200' },
    senior_high: { label: 'Grades 11–12', className: 'bg-amber-50 text-amber-800 ring-amber-200' },
    optional: { label: 'Optional', className: 'bg-slate-100 text-slate-600 ring-slate-200' },
    automatic: { label: 'Fills in by itself', className: 'bg-blue-50 text-blue-700 ring-blue-200' },
};

/**
 * What to type in each column of the encoding template, and what not to.
 * The same text is on the Excel template's HOW TO FILL IN tab; the CSV
 * template has only headers, so this is its guide.
 */
export function ImportGuide({ guide }: { guide: ImportGuideData }) {
    const [requiredOnly, setRequiredOnly] = useState(false);
    const columns = requiredOnly ? guide.columns.filter((c) => c.need === 'required' || c.need === 'senior_high') : guide.columns;

    return (
        <section className="panel space-y-6 p-6" aria-labelledby="import-guide">
            <div>
                <h2 id="import-guide" className="flex items-center gap-2 text-xl font-bold text-slate-900">
                    <Icon name="menu_book" className="text-primary" /> How to fill in the template
                </h2>
                <p className="mt-1 text-sm text-slate-600">
                    For both the Excel and the CSV template. The Excel template has this guide too, on its HOW TO FILL IN tab, and shows a short tip
                    when you select a cell.
                </p>
            </div>

            <div>
                <h3 className="text-base font-semibold text-slate-900">Enrollment status (column {guide.status_column})</h3>
                <p className="mb-3 text-sm text-slate-600">
                    Only two words are understood here: <Code>ENROLLED</Code> and <Code>CANCELLED</Code>.
                </p>
                <DoDontLists list={guide.status} />
            </div>

            <div>
                <h3 className="mb-3 text-base font-semibold text-slate-900">The whole sheet</h3>
                <DoDontLists list={guide.general} />
            </div>

            <details className="group rounded-lg border border-slate-200">
                <summary className="flex cursor-pointer items-center gap-2 rounded-lg px-4 py-3 font-semibold text-slate-900 hover:bg-slate-50">
                    <Icon name="chevron_right" className="transition-transform group-open:rotate-90" />
                    Column by column ({guide.columns.length} columns)
                </summary>
                <div className="border-t border-slate-200 px-4 pt-3 pb-4">
                    <div className="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="Which columns to show">
                        {[
                            [false, 'All columns'],
                            [true, 'Required only'],
                        ].map(([value, label]) => (
                            <button
                                key={String(label)}
                                type="button"
                                aria-pressed={requiredOnly === value}
                                onClick={() => setRequiredOnly(value as boolean)}
                                className={cn(
                                    'rounded-full px-3 py-1 text-sm font-medium ring-1 transition-colors',
                                    requiredOnly === value
                                        ? 'bg-primary text-white ring-transparent'
                                        : 'text-slate-700 ring-slate-300 hover:bg-slate-50',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <ul className="divide-y divide-slate-100">
                        {columns.map((c) => (
                            <li key={c.column} className="py-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="inline-flex h-6 min-w-8 items-center justify-center rounded bg-slate-800 px-1.5 font-mono text-xs font-bold text-white">
                                        {c.column}
                                    </span>
                                    <span className="font-semibold text-slate-900">{c.header}</span>
                                    <span className={cn('rounded-full px-2 py-0.5 text-xs font-semibold ring-1', NEED[c.need].className)}>
                                        {NEED[c.need].label}
                                    </span>
                                </div>
                                <p className="mt-1 text-sm text-slate-700">{c.enter}</p>
                                <p className="mt-1 text-sm text-slate-600">
                                    Example: <Code>{c.example}</Code>
                                </p>
                                {c.avoid && (
                                    <p className="mt-1 flex gap-1.5 text-sm text-red-800">
                                        <Icon name="block" className="mt-0.5 shrink-0 text-base text-red-600" />
                                        <span>{c.avoid}</span>
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            </details>
        </section>
    );
}

function DoDontLists({ list }: { list: DoDont }) {
    return (
        <div className="grid gap-3 md:grid-cols-2">
            <div className="rounded-lg border border-green-200 bg-green-50 p-4">
                <p className="mb-2 flex items-center gap-1.5 text-sm font-bold text-green-800">
                    <Icon name="check_circle" /> Do
                </p>
                <ul className="space-y-1.5 text-sm text-slate-700">
                    {list.do.map((item) => (
                        <li key={item} className="flex gap-2">
                            <Icon name="check" className="mt-0.5 shrink-0 text-base text-green-700" />
                            <span>{item}</span>
                        </li>
                    ))}
                </ul>
            </div>
            <div className="rounded-lg border border-red-200 bg-red-50 p-4">
                <p className="mb-2 flex items-center gap-1.5 text-sm font-bold text-red-800">
                    <Icon name="cancel" /> Don&apos;t
                </p>
                <ul className="space-y-1.5 text-sm text-slate-700">
                    {list.dont.map((item) => (
                        <li key={item} className="flex gap-2">
                            <Icon name="close" className="mt-0.5 shrink-0 text-base text-red-700" />
                            <span>{item}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

function Code({ children }: { children: string }) {
    return <code className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[0.8125rem] text-slate-800">{children}</code>;
}
