<?php

namespace App\Services\Sections;

use App\Enums\EnrollmentStatus;
use App\Exceptions\StaleRecordException;
use App\Models\Enrollment;
use App\Models\Section;
use Illuminate\Support\Facades\DB;

/**
 * Puts enrolled learners into sections without ever going over capacity.
 *
 * The section row is locked while its seats are counted, so two key
 * teachers assigning into the last seat at the same moment cannot both win.
 */
class SectionAssigner
{
    public function assign(Enrollment $enrollment, Section $section): void
    {
        DB::transaction(function () use ($enrollment, $section) {
            $section = Section::lockForUpdate()->findOrFail($section->id);
            $enrollment = Enrollment::lockForUpdate()->findOrFail($enrollment->id);

            $this->ensureFits($enrollment, $section);

            if ($enrollment->section_id === $section->id) {
                return;
            }

            if ($this->filled($section) >= $section->capacity) {
                throw new StaleRecordException("{$section->name} is full ({$section->capacity} learners).");
            }

            $enrollment->update(['section_id' => $section->id]);
        });
    }

    public function unassign(Enrollment $enrollment): void
    {
        $enrollment->update(['section_id' => null]);
    }

    public function clear(Section $section): int
    {
        return DB::transaction(function () use ($section) {
            $learners = Enrollment::where('section_id', $section->id)->lockForUpdate()->get();
            $learners->each(fn (Enrollment $e) => $e->update(['section_id' => null]));

            return $learners->count();
        });
    }

    /**
     * First come, first served, like the legacy auto-assign: learners in the
     * order they were encoded go into the first matching section (in the
     * order sections were created) that still has room.
     *
     * @return array{assigned: int, unplaced: int}
     */
    public function autoAssign(int $schoolYearId, int $grade, ?int $strandId): array
    {
        return DB::transaction(function () use ($schoolYearId, $grade, $strandId) {
            $sections = Section::where('school_year_id', $schoolYearId)
                ->where('grade_level', $grade)
                ->where('is_active', true)
                ->when($strandId, fn ($q) => $q->where('strand_id', $strandId))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $free = $sections->mapWithKeys(fn (Section $s) => [$s->id => $s->capacity - $this->filled($s)])->all();

            $learners = Enrollment::where('school_year_id', $schoolYearId)
                ->where('grade_level', $grade)
                ->where('status', EnrollmentStatus::Enrolled)
                ->whereNull('section_id')
                ->when($strandId, fn ($q) => $q->where('strand_id', $strandId))
                ->orderByRaw('encoded_on is null')
                ->orderBy('encoded_on')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $assigned = 0;
            foreach ($learners as $learner) {
                $section = $sections->first(fn (Section $s) => $free[$s->id] > 0 && ($s->strand_id === null || $s->strand_id === $learner->strand_id));
                if (! $section) {
                    continue;
                }
                $learner->update(['section_id' => $section->id]);
                $free[$section->id]--;
                $assigned++;
            }

            return ['assigned' => $assigned, 'unplaced' => $learners->count() - $assigned];
        });
    }

    public function filled(Section $section): int
    {
        return Enrollment::where('section_id', $section->id)->count();
    }

    private function ensureFits(Enrollment $enrollment, Section $section): void
    {
        if ($enrollment->status !== EnrollmentStatus::Enrolled) {
            throw new StaleRecordException('Only enrolled learners can be placed in a section.');
        }
        if ($enrollment->school_year_id !== $section->school_year_id || $enrollment->grade_level !== $section->grade_level) {
            throw new StaleRecordException("{$section->name} is for a different grade or school year.");
        }
        if ($section->strand_id !== null && $enrollment->strand_id !== $section->strand_id) {
            throw new StaleRecordException("{$section->name} is for a different strand.");
        }
        if (! $section->is_active) {
            throw new StaleRecordException("{$section->name} is inactive.");
        }
    }
}
