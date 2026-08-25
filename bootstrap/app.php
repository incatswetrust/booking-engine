<?php

use App\Http\Errors\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureApiKeyScope;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureUserNotBanned;
use App\Http\Middleware\TouchUserActivity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration as SentryIntegration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->append(AssignRequestId::class);
        $middleware->alias([
            'idempotent' => EnsureIdempotency::class,
            'api-key-scope' => EnsureApiKeyScope::class,
            'not-banned' => EnsureUserNotBanned::class,
            'touch-activity' => TouchUserActivity::class,
            'platform-admin' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return app(ApiExceptionRenderer::class)->render($e, $request);
            }
        });

        SentryIntegration::handles($exceptions);
    })->create();
