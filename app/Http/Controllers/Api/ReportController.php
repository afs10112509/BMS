<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Services\ReportBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class ReportController extends Controller
{
    public const TYPES = [
        'ringkasan',
        'kategori',
        'akun',
        'transaksi',
        'transfer',
        'servis',
        'absensi',
        'gaji',
        'upah',
        'closing',
        'rekonsiliasi',
    ];

    public function __construct(
        protected ReportBuilder $reports,
    ) {}

    public function show(Request $request, string $type): JsonResponse
    {
        $this->assertType($type);
        $user = $request->user();
        $this->authorizeType($user, $type);

        $filters = $this->reports->normalizeFilters($user, $request->all());
        $data = $this->reports->build($type, $filters);

        return response()->json([
            'message' => 'Laporan berhasil dibuat.',
            'meta' => $this->reports->meta($user, $filters, $type),
            'filters' => $filters,
            'data' => $data,
        ]);
    }

    /**
     * Buat URL bertanda tangan agar browser mengunduh PDF secara native
     * (menghindari blob URL yang sering gagal dibuka di Nitro/viewer lain).
     */
    public function pdfLink(Request $request, string $type): JsonResponse
    {
        $this->assertType($type);
        $user = $request->user();
        $this->authorizeType($user, $type);

        $filters = $this->reports->normalizeFilters($user, $request->all());

        $params = array_filter([
            'type' => $type,
            'user_id' => $user->id,
            'branch_id' => $filters['branch_id'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'type_filter' => $filters['type'],
            'category_id' => $filters['category_id'],
            'account_id' => $filters['account_id'],
            'q' => $filters['q'],
            'disposition' => $request->string('disposition', 'attachment')->toString() === 'inline' ? 'inline' : 'attachment',
        ], fn ($v) => $v !== null && $v !== '');

        URL::forceRootUrl($request->getSchemeAndHttpHost());

        $url = URL::temporarySignedRoute(
            'api.reports.pdf-file',
            now()->addMinutes(10),
            $params
        );

        return response()->json([
            'message' => 'Tautan unduhan PDF siap.',
            'url' => $url,
            'expires_in' => 600,
        ]);
    }

    public function pdfFile(Request $request, string $type): Response
    {
        $this->assertType($type);

        try {
            $user = User::query()->findOrFail($request->integer('user_id'));
            $this->authorizeType($user, $type);

            $input = $request->all();
            if ($request->filled('type_filter')) {
                $input['type'] = $request->string('type_filter')->toString();
            }

            $filters = $this->reports->normalizeFilters($user, $input);
            $payload = [
                'meta' => $this->reports->meta($user, $filters, $type),
                'filters' => $filters,
                'data' => $this->reports->build($type, $filters),
                'type' => $type,
            ];

            $landscape = in_array($type, ['transaksi', 'gaji', 'upah', 'servis'], true);

            $pdf = Pdf::loadView('reports.pdf', $payload)
                ->setPaper('a4', $landscape ? 'landscape' : 'portrait');

            $binary = $pdf->output();
            if ($binary === '' || ! str_starts_with($binary, '%PDF')) {
                throw new \RuntimeException('Output PDF tidak valid.');
            }

            $filename = 'laporan-'.$type.'-'.$filters['date_from'].'-'.$filters['date_to'].'.pdf';
            $disposition = $request->string('disposition', 'attachment')->toString() === 'inline'
                ? 'inline'
                : 'attachment';

            return response($binary, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
                'Cache-Control' => 'private, max-age=0, must-revalidate',
                'Content-Length' => (string) strlen($binary),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable $e) {
            report($e);

            // PDF minimal bertitel error agar unduhan tidak kosong/korup.
            $safeTitle = htmlspecialchars($this->reports->title($type), ENT_QUOTES, 'UTF-8');
            $safeMsg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#111;padding:24px}
h1{font-size:18px;color:#0F766E;margin:0 0 8px}
.box{border:1px solid #CBD5E1;padding:12px;background:#F8FAFC;margin-top:12px}
</style></head><body>
<div style="border-bottom:2px solid #0F766E;padding-bottom:8px;margin-bottom:12px">
  <div style="font-size:10px;font-weight:bold;color:#0F766E;letter-spacing:1px">BMS</div>
  <h1>{$safeTitle}</h1>
  <p>Dokumen tidak dapat digenerate sepenuhnya.</p>
</div>
<div class="box"><strong>Pesan:</strong> {$safeMsg}</div>
</body></html>
HTML;

            $binary = Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();
            $filename = 'laporan-'.$type.'-error.pdf';

            return response($binary, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'private, max-age=0, must-revalidate',
                'Content-Length' => (string) strlen($binary),
            ]);
        }
    }

    /** @deprecated Digunakan fallback; prefer pdfLink + pdfFile */
    public function pdf(Request $request, string $type): Response
    {
        $request->merge(['user_id' => $request->user()->id]);

        return $this->pdfFile($request, $type);
    }

    protected function assertType(string $type): void
    {
        abort_unless(in_array($type, self::TYPES, true), 404, 'Jenis laporan tidak ditemukan.');
    }

    protected function authorizeType(User $user, string $type): void
    {
        if ($type === 'gaji' && ! $user->isOwner()) {
            abort(403, 'Laporan gaji hanya untuk owner.');
        }

        if (in_array($type, ['servis', 'closing'], true) && $user->isAdmin()) {
            $branch = Branch::query()->with('branchType')->find($user->branch_id);
            if ($branch?->isWorkshop()) {
                abort(403, 'Laporan ini hanya untuk cabang konter.');
            }
        }

        if ($type === 'upah' && $user->isAdmin()) {
            $branch = Branch::query()->with('branchType')->find($user->branch_id);
            if (! $branch?->isWorkshop()) {
                abort(403, 'Laporan upah hanya untuk cabang bengkel.');
            }
        }
    }
}
