<?php

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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/app/');
        $middleware->redirectUsersTo('/app/');

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'admin.branch' => \App\Http\Middleware\EnsureAdminHasBranch::class,
            'branch.type' => \App\Http\Middleware\CheckBranchType::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $wantsJson = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Anda belum masuk. Silakan login terlebih dahulu.',
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            $raw = trim((string) $e->getMessage());
            $message = ($raw !== '' && $raw !== 'This action is unauthorized.')
                ? $raw
                : 'Anda tidak memiliki izin untuk melakukan aksi ini.';

            return response()->json(['message' => $message], 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Data yang diminta tidak ditemukan.',
            ], 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            if ($e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json([
                    'message' => 'Data yang diminta tidak ditemukan.',
                ], 404);
            }

            return response()->json([
                'message' => 'Endpoint atau sumber daya tidak ditemukan.',
            ], 404);
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Data yang diberikan tidak valid.',
                'errors' => $e->errors(),
            ], $e->status);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            // Biarkan ValidationException / Auth* ditangani render khusus di atas
            // (urutan Laravel: render paling spesifik dulu; HttpException tetap catch-all).
            $status = $e->getStatusCode();
            $fallback = match ($status) {
                401 => 'Anda belum masuk. Silakan login terlebih dahulu.',
                403 => 'Anda tidak memiliki izin untuk melakukan aksi ini.',
                404 => 'Data yang diminta tidak ditemukan.',
                405 => 'Metode HTTP tidak diizinkan.',
                419 => 'Sesi telah berakhir. Muat ulang halaman dan coba lagi.',
                429 => 'Terlalu banyak permintaan. Silakan coba lagi nanti.',
                503 => 'Layanan sedang tidak tersedia. Silakan coba lagi nanti.',
                default => $status >= 500
                    ? 'Terjadi kesalahan pada server.'
                    : 'Terjadi kesalahan pada permintaan.',
            };

            $message = trim((string) $e->getMessage());
            $englishDefaults = [
                'Unauthorized',
                'Forbidden',
                'Not Found',
                'Page Expired',
                'Too Many Requests',
                'Server Error',
                'Service Unavailable',
                'Invalid signature.',
                'Unauthenticated.',
                'This action is unauthorized.',
            ];

            if ($message === '' || in_array($message, $englishDefaults, true)) {
                $message = $fallback;
            }

            return response()->json(['message' => $message], $status);
        });
    })->create();
