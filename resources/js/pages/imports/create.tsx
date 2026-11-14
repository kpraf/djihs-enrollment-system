import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FileSpreadsheet, LoaderCircle, TriangleAlert } from 'lucide-react';
import { FormEventHandler } from 'react';

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
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Import spreadsheet', href: '/imports' }];

export default function ImportCreate({ schoolYears, preview, result }: Props) {
    const upload = useForm<{ file: File | null; school_year_id: number | string }>({
        file: null,
        school_year_id: schoolYears.find((y) => y.is_active)?.id ?? schoolYears[0]?.id ?? '',
    });
    const confirm = useForm({ token: preview?.token ?? '' });

    const check: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('imports.preview'), { forceFormData: true });
    };

    const yearName = (id: number) => schoolYears.find((y) => y.id === id)?.name;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import spreadsheet" />
            <div className="flex max-w-4xl flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Import encoding spreadsheet</h1>
                    <p className="text-muted-foreground text-sm">
                        Upload the school's ENROLLMENT_ENCODING workbook. The system reads the MAIN_ENCODING_SHEET tab. Importing the same file again
                        updates learners instead of adding duplicates.
                    </p>
                </div>

                {!preview && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">1. Check the file</CardTitle>
                            <CardDescription>Nothing is saved yet. You will see what will change first.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={check} className="grid gap-4 sm:grid-cols-[1fr_12rem_auto] sm:items-end">
                                <div className="grid gap-2">
                                    <Label htmlFor="file">Workbook (.xlsx)</Label>
                                    <Input
                                        id="file"
                                        type="file"
                                        accept=".xlsx"
                                        onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                                        required
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="school_year_id">School year</Label>
                                    <NativeSelect
                                        id="school_year_id"
                                        value={upload.data.school_year_id}
                                        onChange={(e) => upload.setData('school_year_id', e.target.value)}
                                    >
                                        {schoolYears.map((y) => (
                                            <option key={y.id} value={y.id}>
                                                {y.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>
                                <Button type="submit" disabled={upload.processing || !upload.data.file}>
                                    {upload.processing ? <LoaderCircle className="size-4 animate-spin" /> : <FileSpreadsheet className="size-4" />}
                                    Check file
                                </Button>
                            </form>
                            <InputError className="mt-2" message={upload.errors.file ?? upload.errors.school_year_id} />
                        </CardContent>
                    </Card>
                )}

                {preview && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">2. Review and import</CardTitle>
                            <CardDescription>
                                {preview.filename} into school year {yearName(preview.school_year_id)}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <SummaryGrid summary={preview} />
                            <Notes summary={preview} />
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    disabled={confirm.processing}
                                    onClick={() => confirm.post(route('imports.store'))}
                                    className="bg-green-700 hover:bg-green-800"
                                >
                                    {confirm.processing && <LoaderCircle className="size-4 animate-spin" />}
                                    Import {preview.rows_read - preview.skipped.length} learners
                                </Button>
                                <Button variant="outline" disabled={confirm.processing} onClick={() => router.delete(route('imports.destroy'))}>
                                    Cancel
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {result && !preview && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Import finished</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <SummaryGrid summary={result} />
                            <Notes summary={result} />
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

function SummaryGrid({ summary }: { summary: ImportSummary }) {
    const verb = summary.dry_run ? 'will be' : '';
    const items = [
        ['Rows read', summary.rows_read],
        [`New learners ${verb}`.trim(), summary.students_created],
        [`Existing learners ${verb ? 'to update' : 'updated'}`, summary.students_updated],
        ['Rows skipped', summary.skipped.length],
    ] as const;

    return (
        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {items.map(([label, value]) => (
                <div key={label} className="rounded-lg border p-3">
                    <dt className="text-muted-foreground text-xs">{label}</dt>
                    <dd className="text-2xl font-semibold tabular-nums">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

function Notes({ summary }: { summary: ImportSummary }) {
    if (summary.skipped.length === 0 && summary.warnings.length === 0) {
        return <p className="text-sm text-green-700">No problems found.</p>;
    }

    return (
        <div className="grid gap-3">
            {summary.skipped.length > 0 && (
                <NoteList title="These rows will not be imported. Fix them in the sheet and upload again." notes={summary.skipped} tone="error" />
            )}
            {summary.warnings.length > 0 && (
                <NoteList
                    title="These rows will be imported, but please check them. Row numbers match the spreadsheet."
                    notes={summary.warnings}
                    tone="warning"
                />
            )}
        </div>
    );
}

function NoteList({ title, notes, tone }: { title: string; notes: RowNote[]; tone: 'error' | 'warning' }) {
    return (
        <div
            className={
                tone === 'error' ? 'border-destructive/40 rounded-lg border p-3' : 'rounded-lg border border-amber-300 p-3 dark:border-amber-800'
            }
        >
            <p className="mb-2 flex items-center gap-2 text-sm font-medium">
                <TriangleAlert className={tone === 'error' ? 'text-destructive size-4' : 'size-4 text-amber-600'} />
                {title}
            </p>
            <ul className="max-h-64 space-y-1 overflow-auto text-sm">
                {notes.map((n, i) => (
                    <li key={i}>
                        <span className="text-muted-foreground tabular-nums">Row {n.row}:</span> {n.message}
                    </li>
                ))}
            </ul>
        </div>
    );
}
