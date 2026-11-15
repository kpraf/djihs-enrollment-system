<?php

namespace App\Http\Middleware;

use App\Enums\Ability;
use App\Enums\EnrollmentStatus;
use App\Enums\TextSize;
use App\Models\Enrollment;
use App\Models\RevisionRequest;
use App\Models\SchoolYear;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'must_change_password' => $user->must_change_password,
                ] : null,
                // The frontend only uses these to hide links. Every route
                // checks the same abilities again on the server.
                'abilities' => $user
                    ? array_values(array_map(fn (Ability $a) => $a->value, array_filter(
                        Ability::cases(), fn (Ability $a) => $user->hasAbility($a),
                    )))
                    : [],
            ],
            'textSize' => fn () => [
                'value' => AppServiceProvider::textSize()->value,
                'percent' => AppServiceProvider::textSize()->percent(),
                'options' => TextSize::options(),
            ],
            // A school photo for the sign-in screen, if one was added to public/images.
            'loginBackground' => fn () => $user ? null : $this->loginBackground(),
            'activeSchoolYear' => fn () => $user ? SchoolYear::active()?->only(['id', 'name', 'is_enrollment_open']) : null,
            // The count beside Review Enrollments in the sidebar.
            'pendingReviews' => fn () => $user?->hasAbility(Ability::ReviewEnrollments) ? $this->pendingReviews($user) : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // A temporary password, shown once in a dialog with a copy button.
                'credential' => fn () => $request->session()->get('credential'),
            ],
        ];
    }

    private function loginBackground(): ?string
    {
        foreach (['jpg', 'jpeg', 'webp', 'png'] as $ext) {
            $file = public_path("images/login-background.{$ext}");
            if (is_file($file)) {
                return "/images/login-background.{$ext}?v=".filemtime($file);
            }
        }

        return null;
    }

    /**
     * Pending enrollments this school year, plus revision requests for those
     * who approve them: the same numbers as on Review Enrollments.
     */
    private function pendingReviews(User $user): int
    {
        $year = SchoolYear::active();
        $enrollments = $year ? Enrollment::where('school_year_id', $year->id)->where('status', EnrollmentStatus::Pending)->count() : 0;
        $revisions = $user->hasAbility(Ability::EditEnrollments) ? RevisionRequest::where('status', RevisionRequest::PENDING)->count() : 0;

        return $enrollments + $revisions;
    }
}
