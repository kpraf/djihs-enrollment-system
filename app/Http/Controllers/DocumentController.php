<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\DocumentSubmission;
use App\Models\Enrollment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The enrollment document checklist. Each document is its own row, saved
 * with an upsert, so two staff ticking different documents at the same time
 * never overwrite each other.
 */
class DocumentController extends Controller
{
    public function update(Request $request, Enrollment $enrollment): RedirectResponse
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::enum(DocumentType::class)],
            'is_submitted' => ['required', 'boolean'],
            'is_verified' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // A document cannot be verified without being submitted.
        $submitted = filter_var($data['is_submitted'], FILTER_VALIDATE_BOOLEAN);
        $verified = $submitted && filter_var($data['is_verified'], FILTER_VALIDATE_BOOLEAN);

        $document = DocumentSubmission::firstOrNew(['enrollment_id' => $enrollment->id, 'document_type' => $data['document_type']]);
        $document->fill(['is_submitted' => $submitted, 'is_verified' => $verified, 'notes' => $data['notes'] ?? null, 'updated_by' => $request->user()->id]);

        try {
            DB::transaction(fn () => $document->save());
        } catch (UniqueConstraintViolationException) {
            // Someone created this row a moment ago; update theirs instead.
            DocumentSubmission::where('enrollment_id', $enrollment->id)->where('document_type', $data['document_type'])->first()
                ->update(['is_submitted' => $submitted, 'is_verified' => $verified, 'notes' => $data['notes'] ?? null, 'updated_by' => $request->user()->id]);
        }

        return back()->with('success', DocumentType::from($data['document_type'])->label().' updated.');
    }
}
