<?php

namespace App\Providers;

use App\Enums\Ability;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
