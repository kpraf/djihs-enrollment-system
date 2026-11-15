<?php

namespace App\Http\Controllers;

use App\Models\SchoolYear;
use App\Services\Import\EncodingSheetImporter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;

/**
 * Two-step upload of the encoding workbook: check first, then import.
 * The check runs the real import inside a transaction and rolls it back.
 */
class ImportController extends Controller
{
    private const DIR = 'imports';

    public function create(Request $request): Response
    {
        $pending = $request->session()->get('import');

        return Inertia::render('imports/create', [
            'schoolYears' => SchoolYear::orderByDesc('start_year')->get()->map->only(['id', 'name', 'is_active'])->values(),
            'preview' => $pending ? [...$pending['result'], 'token' => $pending['token'], 'school_year_id' => $pending['school_year_id'], 'filename' => $pending['filename']] : null,
            'result' => $request->session()->get('import_result'),
        ]);
    }

    public function preview(Request $request, EncodingSheetImporter $importer): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:20480'],
            'school_year_id' => ['required', 'exists:school_years,id'],
        ]);

        $this->discardPending($request);

        $token = (string) Str::uuid();
        $extension = strtolower($data['file']->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
        $path = $data['file']->storeAs(self::DIR, "{$token}.{$extension}");

        try {
            $result = $importer->import(Storage::path($path), SchoolYear::findOrFail($data['school_year_id']), $request->user(), dryRun: true);
        } catch (InvalidArgumentException $e) {
            Storage::delete($path);

            return back()->withErrors(['file' => $e->getMessage()]);
        } catch (SpreadsheetException) {
            Storage::delete($path);

            return back()->withErrors(['file' => 'This file could not be read. Save it again from Excel as .xlsx or .csv and retry.']);
        }

        $request->session()->put('import', [
            'token' => $token,
            'path' => $path,
            'school_year_id' => (int) $data['school_year_id'],
            'filename' => $data['file']->getClientOriginalName(),
            'result' => $result->toArray(),
        ]);

        return to_route('imports.create');
    }

    public function store(Request $request, EncodingSheetImporter $importer): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string']]);
        $pending = $request->session()->get('import');

        if (! $pending || $pending['token'] !== $request->input('token') || ! Storage::exists($pending['path'])) {
            $this->discardPending($request);

            return to_route('imports.create')->with('error', 'That upload expired. Please upload the file again.');
        }

        // One import at a time. Two imports of overlapping sheets would race
        // each other for the same learners.
        $lock = Cache::lock('enrollment-import', 300);
        if (! $lock->get()) {
            return to_route('imports.create')->with('error', 'Another import is running right now. Wait a minute and click Import again.');
        }

        try {
            $result = $importer->import(Storage::path($pending['path']), SchoolYear::findOrFail($pending['school_year_id']), $request->user());
        } catch (UniqueConstraintViolationException) {
            return to_route('imports.create')->with('error', 'Someone saved one of these learners while the import ran, so nothing was imported. Click Import again.');
        } finally {
            $lock->release();
        }

        $this->discardPending($request);

        return to_route('imports.create')
            ->with('import_result', $result->toArray())
            ->with('success', "Imported {$result->rowsRead} rows.");
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->discardPending($request);

        return to_route('imports.create');
    }

    private function discardPending(Request $request): void
    {
        if ($pending = $request->session()->pull('import')) {
            Storage::delete($pending['path']);
        }
    }
}
