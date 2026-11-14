<?php

namespace App\Http\Controllers;

use App\Enums\Track;
use App\Models\Strand;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StrandController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('strands/index', [
            'strands' => Strand::withCount(['sections', 'enrollments'])->orderBy('sort_order')->orderBy('code')->get()->map(fn (Strand $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'track' => $s->track->value,
                'description' => $s->description,
                'is_active' => $s->is_active,
                'sections_count' => $s->sections_count,
                'enrollments_count' => $s->enrollments_count,
            ]),
            'tracks' => array_map(fn (Track $t) => ['value' => $t->value, 'label' => $t->label()], Track::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            DB::transaction(fn () => Strand::create([...$data, 'sort_order' => (int) Strand::max('sort_order') + 1]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'That strand code was just added by someone else.']);
        }

        return back()->with('success', 'Strand added.');
    }

    public function update(Request $request, Strand $strand): RedirectResponse
    {
        $strand->update($this->validated($request, $strand));

        return back()->with('success', 'Strand updated.');
    }

    public function setActive(Request $request, Strand $strand): RedirectResponse
    {
        $strand->update(['is_active' => $request->validate(['active' => ['required', 'boolean']])['active']]);

        return back()->with('success', $strand->is_active ? 'Strand activated.' : 'Strand deactivated. It no longer appears on the enrollment form.');
    }

    /**
     * Only strands nobody uses can be deleted; others can be deactivated.
     */
    public function destroy(Strand $strand): RedirectResponse
    {
        if ($strand->sections()->exists() || $strand->enrollments()->exists()) {
            return back()->with('error', "{$strand->code} is used by sections or learners. Deactivate it instead.");
        }

        $strand->delete();

        return back()->with('success', 'Strand deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Strand $strand = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('strands', 'code')->ignore($strand)],
            'name' => ['required', 'string', 'max:150'],
            'track' => ['required', Rule::enum(Track::class)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
