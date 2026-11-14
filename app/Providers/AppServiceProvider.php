<?php

namespace App\Providers;

use App\Enums\Ability;
use App\Enums\TextSize;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Every ability in the Ability enum becomes a gate, checked on the server.
        foreach (Ability::cases() as $ability) {
            Gate::define($ability->value, fn (User $user) => $user->hasAbility($ability));
        }

        // Open the edit form: saving directly, or as a revision request.
        Gate::define('change-enrollment', fn (User $user) => $user->hasAbility(Ability::EditEnrollments) || $user->hasAbility(Ability::RequestRevisions));

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Set the text size in the first HTML the browser receives, so pages
        // never flash at the small size before switching.
        View::composer('app', fn ($view) => $view->with('textSize', self::textSize()));

        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }

    /**
     * The user's own choice, else this device's last choice (so a size picked
     * on the login page carries on after signing in), else normal.
     */
    public static function textSize(): TextSize
    {
        return request()->user()?->text_size
            ?? TextSize::tryFrom((string) request()->cookie(TextSize::COOKIE))
            ?? TextSize::Normal;
    }
}
