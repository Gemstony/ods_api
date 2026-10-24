<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * Named rate limiters used by `throttle:` middleware on the auth routes.
     *
     * Both return the ODS JSON envelope on 429 (instead of Laravel's
     * default empty-body response), so Flutter's error interceptor can
     * surface a user-friendly message.
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $key = ($request->input('email') ?? 'anonymous') . '|' . $request->ip();

            return Limit::perMinute(5)
                ->by($key)
                ->response(fn () => response()->json([
                    'success' => false,
                    'message' => 'Too many login attempts. Please wait a minute and try again.',
                    'errors'  => null,
                ], 429));
        });

        RateLimiter::for('refresh', function (Request $request) {
            return Limit::perMinute(20)
                ->by($request->ip())
                ->response(fn () => response()->json([
                    'success' => false,
                    'message' => 'Too many refresh attempts. Please slow down.',
                    'errors'  => null,
                ], 429));
        });
    }
}