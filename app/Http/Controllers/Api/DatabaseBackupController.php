<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseBackupController extends Controller
{
    public function __construct(
        protected DatabaseBackupService $backups,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'Daftar backup database berhasil diambil.',
            'data' => $this->backups->listBackups(),
            'meta' => [
                'keep' => (int) config('bms.backup.keep', 10),
                'confirm_phrase' => $this->backups->confirmPhrase(),
                'upload_max_kb' => (int) config('bms.backup.upload_max_kb', 20480),
                'schedule' => $this->backups->getSchedule(),
                'note' => 'Backup & restore hanya untuk Owner. Restore mengganti seluruh data database.',
            ],
        ]);
    }

    public function updateSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'time' => ['required', 'string', 'regex:/^([01]?\d|2[0-3]):([0-5]\d)$/'],
        ], [
            'time.regex' => 'Format jam tidak valid. Gunakan HH:MM, contoh: 02:00.',
        ]);

        try {
            $schedule = $this->backups->updateSchedule([
                'enabled' => (bool) $data['enabled'],
                'time' => $data['time'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Gagal menyimpan jadwal backup.',
            ], 422);
        }

        return response()->json([
            'message' => 'Jadwal backup otomatis disimpan.',
            'data' => $schedule,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $this->assertOwnerPassword($request, $data['password']);

        try {
            $meta = $this->backups->createBackup();
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Gagal membuat backup database.',
            ], 500);
        }

        $url = $this->signedDownloadUrl($request, $meta['filename'], (int) $request->user()->id);

        return response()->json([
            'message' => 'Backup database berhasil dibuat.',
            'data' => [
                'filename' => $meta['filename'],
                'size' => $meta['size'],
                'size_label' => $meta['size_label'],
                'created_at' => $meta['created_at'],
                'download_url' => $url,
                'download_path' => '/api/system/database-backups/'.$meta['filename'].'/download',
                'expires_in' => 600,
            ],
        ], 201);
    }

    public function upload(Request $request): JsonResponse
    {
        $maxKb = max(1024, (int) config('bms.backup.upload_max_kb', 20480));
        $data = $request->validate([
            'password' => ['required', 'string'],
            'file' => ['required', 'file', 'max:'.$maxKb],
        ]);

        $this->assertOwnerPassword($request, $data['password']);

        try {
            $meta = $this->backups->storeUploadedDump($request->file('file'));
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Gagal mengunggah file backup.',
            ], 422);
        }

        return response()->json([
            'message' => 'File backup berhasil diunggah ke server.',
            'data' => $meta,
        ], 201);
    }

    public function restore(Request $request, string $file): JsonResponse
    {
        $phrase = $this->backups->confirmPhrase();
        $data = $request->validate([
            'password' => ['required', 'string'],
            'confirm_phrase' => ['required', 'string'],
            'skip_safety_backup' => ['sometimes', 'boolean'],
        ]);

        $this->assertOwnerPassword($request, $data['password']);

        if (trim((string) $data['confirm_phrase']) !== $phrase) {
            throw ValidationException::withMessages([
                'confirm_phrase' => ['Ketik '.$phrase.' untuk mengonfirmasi restore.'],
            ]);
        }

        try {
            $this->backups->resolvePath($file);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        try {
            $meta = $this->backups->restoreBackup(
                $file,
                ! (bool) ($data['skip_safety_backup'] ?? false)
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Gagal memulihkan database.',
            ], 500);
        }

        $msg = 'Database berhasil dipulihkan dari '.$meta['filename'].'.';
        if (! empty($meta['safety_backup'])) {
            $msg .= ' Backup pengaman: '.$meta['safety_backup'].'.';
        }

        return response()->json([
            'message' => $msg,
            'data' => $meta,
        ]);
    }

    public function download(Request $request, string $file): BinaryFileResponse|JsonResponse
    {
        try {
            $path = $this->backups->resolvePath($file);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->download($path, $file, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    public function downloadLink(Request $request, string $file): JsonResponse
    {
        try {
            $this->backups->resolvePath($file);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $url = $this->signedDownloadUrl($request, $file, (int) $request->user()->id);

        return response()->json([
            'message' => 'Tautan unduhan backup siap.',
            'url' => $url,
            'expires_in' => 600,
        ]);
    }

    public function file(Request $request, string $file): BinaryFileResponse|JsonResponse
    {
        $user = User::query()->find($request->integer('user_id'));
        if (! $user || ! $user->isOwner()) {
            return response()->json([
                'message' => 'Akses unduhan ditolak.',
            ], 403);
        }

        try {
            $path = $this->backups->resolvePath($file);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->download($path, $file, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    protected function assertOwnerPassword(Request $request, string $password): void
    {
        $user = $request->user();
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Kata sandi Owner tidak sesuai.'],
            ]);
        }
    }

    protected function signedDownloadUrl(Request $request, string $file, int $userId): string
    {
        // Prefer APP_URL (HTTPS produksi) agar signed URL tidak jadi http di balik proxy.
        $root = rtrim((string) config('app.url'), '/');
        if ($root === '') {
            $root = $request->getSchemeAndHttpHost();
        }
        URL::forceRootUrl($root);

        return URL::temporarySignedRoute(
            'api.system.database-backups.file',
            now()->addMinutes(10),
            [
                'file' => $file,
                'user_id' => $userId,
            ]
        );
    }
}
