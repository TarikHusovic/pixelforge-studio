<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('admin', fn (User $user): bool => $user->is_admin);

        // Per user, since credits are free and every account shares one Pixelforge key and its
        // daily quota. Over the limit, the form gets a toast rather than an error page.
        $tooFast = function (): RedirectResponse {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Too many requests at once. Please wait a minute and try again.']);

            return back();
        };
        RateLimiter::for('generations', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id)->response($tooFast));
        RateLimiter::for('credit-purchases', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id)->response($tooFast));

        // `composer dev` also runs the scheduler, which polls generations with overdue webhooks.
        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Outside production, lazy loading (N+1), reading a missing attribute and filling one
        // that isn't fillable throw instead of passing silently.
        Model::shouldBeStrict(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
