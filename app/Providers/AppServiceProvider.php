<?php

namespace App\Providers;

use App\Services\ClickPesa\ClickPesaClient;
use App\Services\ClickPesa\ClickPesaTokenProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ─── ClickPesa (Phase 11.1) ─────────────────────────
        $this->app->singleton(ClickPesaTokenProvider::class, function ($app) {
            return new ClickPesaTokenProvider(
                clientId: (string) config('clickpesa.client_id'),
            );
        });

        $this->app->singleton(ClickPesaClient::class, function ($app) {
            return new ClickPesaClient(
                baseUrl: (string) config('clickpesa.base_url'),
                clientId: (string) config('clickpesa.client_id'),
                clientSecret: (string) config('clickpesa.client_secret'),
                defaultCurrency: (string) config('clickpesa.currency', 'TZS'),
                timeout: (int) config('clickpesa.timeout', 30),
                connectTimeout: (int) config('clickpesa.connect_timeout', 10),
                tokenTtlMinutes: (int) config('clickpesa.token_ttl_minutes', 55),
                tokenProvider: $app->make(ClickPesaTokenProvider::class),
            );
        });
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    protected function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $key = ($request->input('email') ?? 'anonymous') . '|' . $request->ip();

            return Limit::perMinute(5)
                ->by($key)
                ->response(fn() => response()->json([
                    'success' => false,
                    'message' => 'Too many login attempts. Please wait a minute and try again.',
                    'errors' => null,
                ], 429));
        });

        RateLimiter::for('refresh', function (Request $request) {
            return Limit::perMinute(20)
                ->by($request->ip())
                ->response(fn() => response()->json([
                    'success' => false,
                    'message' => 'Too many refresh attempts. Please slow down.',
                    'errors' => null,
                ], 429));
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(3)
                ->by($request->ip())
                ->response(fn() => response()->json([
                    'success' => false,
                    'message' => 'Too many registration attempts. Please wait a minute and try again.',
                    'errors' => null,
                ], 429));
        });
    }
}