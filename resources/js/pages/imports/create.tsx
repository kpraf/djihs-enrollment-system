import { InputError } from '@/components/form';
import { Icon } from '@/components/icon';
import { ImportGuide, type ImportGuideData } from '@/components/import-guide';
import { PageHeader } from '@/components/page-header';
import { BusyLabel, Spinner } from '@/components/spinner';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

interface RowNote {
    row: number;
    message: string;
}

interface ImportSummary {
    dry_run: boolean;
    rows_read: number;
    students_created: number;
    students_updated: number;
    enrollments_created: number;
    enrollments_updated: number;
    skipped: RowNote[];
    warnings: RowNote[];
}

interface Props {
    schoolYears: { id: number; name: string; is_active: boolean }[];
    preview: (ImportSummary & { token: string; school_year_id: number; filename: string }) | null;
    result: ImportSummary | null;
    guide: ImportGuideData;
}

export default function ImportCreate({ schoolYears, preview, result, guide }: Props) {
    const upload = useForm<{ file: File | null; school_year_id: number | string }>({
        file: null,
        school_year_id: schoolYears.find((y) => y.is_active)?.id ?? schoolYears[0]?.id ?? '',
    });
    const [importing, setImporting] = useState(false);
    const [dragging, setDragging] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    const check: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('imports.preview'), { forceFormData: true, onSuccess: () => upload.reset('file') });
    };

    // Send the token from the preview on screen now. A form object created
    // before the preview existed would still hold an empty token.
    const confirmImport = () => {
        if (!preview) return;
        setImporting(true);
        router.post(route('imports.store'), { token: preview.token }, { onFinish: () => setImporting(false) });
    };

    const yearName = (id: number) => schoolYears.find((y) => y.id === id)?.name;
    const busy = upload.processing || importing;

    // Closing the tab mid-way would lose the check or stop the import.
    useEffect(() => {
        if (!busy) return;
        const warn = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [busy]);

    return (
        <AppLayout>
            <Head title="Bulk Import" />
            <PageHeader
                title="Bulk Import Students"
                description="Upload the school's ENROLLMENT_ENCODING workbook. Importing the same file again updates learners instead of adding duplicates."
                actions={
                    <Link href="/enrollments/create" className="btn-outline">
                        <Icon name="chevron_left" /> Back to enrollment
                    </Link>
                }
            />

            {upload.processing && (
                <Working
                    title="Checking your file"
                    detail={
                        upload.progress?.percentage !== undefined && upload.progress.percentage < 100
                            ? `Uploading… ${upload.progress.percentage}%`
                            : 'Reading every row and checking it. Nothing is saved yet.'
                    }
                />
            )}
            {importing && preview && (
                <Working
                    title={`Importing ${(preview.rows_read - preview.skipped.length).toLocaleString()} learners`}
                    detail="Saving learners and their enrollments. If anything goes wrong, nothing is saved."
                />
            )}

            <div className="max-w-4xl space-y-6">
                {!preview && (
                    <form onSubmit={check} className="panel space-y-6 p-6">
                        <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
                            <label htmlFor="school_year_id" className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-800">
                                <Icon name="calendar_month" className="text-primary" /> School Year for Import <span className="text-red-500">*</span>
                            </label>
                            <select
                                id="school_year_id"
                                className="field-input-sm h-11 max-w-xs bg-white"
                                value={upload.data.school_year_id}
                                onChange={(e) => upload.setData('school_year_id', e.target.value)}
                            >
                                {schoolYears.map((y) => (
                                    <option key={y.id} value={y.id}>
                                        {y.name}
                                    </option>
                                ))}
                            </select>
                            <p className="mt-2 text-xs text-slate-600">
                                All imported learners will be enrolled for this school year. From an Excel file, the sheet named MAIN_ENCODING_SHEET
                                is read.
                            </p>
                        </div>

                        <div>
                            <p className="mb-2 text-sm font-semibold text-slate-800">Download Template</p>
                            <div className="flex flex-wrap gap-2">
                                <a
                                    href={route('imports.template', { format: 'csv' })}
                                    className="btn bg-green-100 font-medium text-green-700 hover:bg-green-200"
                                >
                                    <Icon name="description" /> CSV Template
                                </a>
                                <a
                                    href={route('imports.template', { format: 'xlsx' })}
                                    className="btn bg-blue-100 font-medium text-blue-700 hover:bg-blue-200"
                                >
                                    <Icon name="table_view" /> Excel Template
                                </a>
                            </div>
                            <p className="mt-1 text-xs text-slate-500">
                                A blank encoding sheet in the school's format. The Excel template has dropdowns, a tip on every column and a HOW TO
                                FILL IN tab. In the CSV, type values exactly as the guide below shows.{' '}
                                <a href="#import-guide" className="text-primary font-medium underline underline-offset-2">
                                    How to fill in the template
                                </a>
                            </p>
                        </div>

                        <div
                            className={`rounded-lg border-2 border-dashed p-8 text-center transition-colors ${dragging ? 'border-primary bg-green-50' : 'border-slate-300'}`}
                            onDragOver={(e) => {
                                e.preventDefault();
                                setDragging(true);
                            }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={(e) => {
                                e.preventDefault();
                                setDragging(false);
                                const f = e.dataTransfer.files?.[0];
                                if (f) upload.setData('file', f);
                            }}
                        >
                            <Icon name="cloud_upload" className="mb-2 text-4xl text-slate-400" />
                            <p className="mb-3 text-base text-slate-700">
                                {upload.data.file ? upload.data.file.name : 'Drag and drop your file here, or'}
                            </p>
                            <input
                                ref={fileInput}
                                id="file"
                                type="file"
                                accept=".xlsx,.csv"
                                className="sr-only"
                                onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                            />
                            <button type="button" className="btn-primary" onClick={() => fileInput.current?.click()}>
                                {upload.data.file ? 'Choose a different file' : 'Browse Files'}
                            </button>
                            <p className="mt-3 text-xs text-slate-500">Supported formats: Excel (.xlsx) or CSV (.csv) • Max size: 20MB</p>
                            <InputError message={upload.errors.file ?? upload.errors.school_year_id} />
                        </div>

                        <div className="flex justify-end">
                            <button type="submit" className="btn-primary" disabled={upload.processing || !upload.data.file}>
                                <BusyLabel busy={upload.processing} busyText="Checking…">
                                    <Icon name="fact_check" /> Check File
                                </BusyLabel>
                            </button>
                        </div>
                    </form>
                )}

                {preview && (
                    <div className="panel space-y-6 p-6">
                        <div>
                            <h2 className="text-xl font-bold text-slate-900">Review before importing</h2>
                            <p className="text-sm text-slate-500">
                                {preview.filename} → school year {yearName(preview.school_year_id)}. Nothing has been saved yet.
                            </p>
                        </div>
                        <Summary summary={preview} />
                        <Notes summary={preview} />
                        {preview.skipped.length + preview.warnings.length > 0 && (
                            <p className="text-sm text-slate-600">
                                Not sure how to fix a row? See{' '}
                                <a href="#import-guide" className="text-primary font-medium underline underline-offset-2">
                                    how to fill in the template
                                </a>{' '}
                                below.
                            </p>
                        )}
                        <div className="flex flex-wrap justify-end gap-3 border-t border-slate-200 pt-4">
                            <button
                                type="button"
                                className="btn-secondary"
                                disabled={importing}
                                onClick={() => router.delete(route('imports.destroy'))}
                            >
                                Cancel
                            </button>
                            <button type="button" className="btn-primary" disabled={importing} onClick={confirmImport}>
                                <BusyLabel busy={importing} busyText="Importing…">
                                    Import {preview.rows_read - preview.skipped.length} learners
                                </BusyLabel>
                            </button>
                        </div>
                    </div>
                )}

                {result && !preview && (
                    <div className="panel space-y-6 p-6">
                        <h2 className="flex items-center gap-2 text-xl font-bold text-slate-900">
                            <Icon name="task_alt" className="text-primary" /> Import finished
                        </h2>
                        <Summary summary={result} />
                        <Notes summary={result} />
                        <Link href="/enrollments" className="btn-primary">
                            View Learners
                        </Link>
                    </div>
                )}

                <ImportGuide guide={guide} />
            </div>
        </AppLayout>
    );
}

function Summary({ summary }: { summary: ImportSummary }) {
    const future = summary.dry_run;
    const items = [
        ['Rows read', summary.rows_read],
        [future ? 'New learners to add' : 'New learners added', summary.students_created],
        [future ? 'Existing learners to update' : 'Existing learners updated', summary.students_updated],
        ['Rows skipped', summary.skipped.length],
    ] as const;

    return (
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            {items.map(([label, value]) => (
                <div key={label} className="rounded-xl border border-slate-200 p-4">
                    <dt className="text-xs text-slate-500">{label}</dt>
                    <dd className="text-2xl font-bold text-slate-900 tabular-nums">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

function Notes({ summary }: { summary: ImportSummary }) {
    if (summary.skipped.length === 0 && summary.warnings.length === 0) {
        return (
            <p className="flex items-center gap-2 text-sm text-emerald-700">
                <Icon name="check_circle" /> No problems found.
            </p>
        );
    }

    return (
        <div className="space-y-4">
            {summary.skipped.length > 0 && (
                <NoteList tone="error" title="These rows will not be imported. Fix them in the sheet and upload again." notes={summary.skipped} />
            )}
            {summary.warnings.length > 0 && (
                <NoteList
                    tone="warning"
                    title="These rows will be imported, but please check them. Row numbers match the spreadsheet."
                    notes={summary.warnings}
                />
            )}
        </div>
    );
}

function NoteList({ title, notes, tone }: { title: string; notes: RowNote[]; tone: 'error' | 'warning' }) {
    return (
        <div className={tone === 'error' ? 'rounded-lg border border-red-200 bg-red-50 p-4' : 'rounded-lg border border-amber-200 bg-amber-50 p-4'}>
            <p className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900">
                <Icon name={tone === 'error' ? 'error' : 'warning'} className={tone === 'error' ? 'text-red-600' : 'text-amber-600'} />
                {title}
            </p>
            <ul className="max-h-72 space-y-1 overflow-auto text-sm text-slate-700">
                {notes.map((n, i) => (
                    <li key={i}>
                        <span className="font-medium text-slate-500 tabular-nums">Row {n.row}:</span> {n.message}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Covers the page while a file is checked or imported, so nobody presses
 * anything twice. A large file can take a minute on a slow server.
 */
function Working({ title, detail }: { title: string; detail: string }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-[2px]">
            <div role="status" aria-live="polite" className="panel w-full max-w-sm p-8 text-center shadow-2xl">
                <Spinner className="text-primary mx-auto size-12" />
                <h2 className="mt-5 text-lg font-bold text-slate-900">{title}</h2>
                <p className="mt-2 text-sm text-slate-600">{detail}</p>
                <p className="mt-4 text-xs text-slate-500">A large file can take a minute. Please keep this page open.</p>
            </div>
        </div>
    );
}
