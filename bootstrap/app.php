<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'role'         => \App\Http\Middleware\EnsureRole::class,
            'branch.scope' => \App\Http\Middleware\EnsureBranchScope::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // ─── Phase 10.3 — delivery orchestration ────────────
        $schedule->command('ods:batch-pending')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        $schedule->command('ods:assign-batched')
            ->everyTwoMinutes()
            ->withoutOverlapping();

        $schedule->command('ods:release-expired')
            ->everyMinute()
            ->withoutOverlapping();

        // ─── Phase 13.2 — SMS dispatch + reconciliation ─────
        $schedule->command('ods:dispatch-sms')
            ->everyMinute()
            ->withoutOverlapping();

        // Beem requires >=5 min after send before delivery reports are
        // meaningful. A daily run at 03:30 covers everything from the
        // previous day; running more often just burns quota.
        $schedule->command('ods:reconcile-sms')
            ->dailyAt('03:30')
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'errors'  => null,
                ], 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Forbidden.',
                    'errors'  => null,
                ], 403);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                    'errors'  => null,
                ], 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Endpoint not found.',
                    'errors'  => null,
                ], 404);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'HTTP error.',
                    'errors'  => null,
                ], $e->getStatusCode());
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && ! config('app.debug')) {
                return response()->json([
                    'success' => false,
                    'message' => 'An unexpected error occurred.',
                    'errors'  => null,
                ], 500);
            }
        });
    })->create();