<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Isolasi modul berdasarkan tipe cabang (PRD §3).
 *
 * Parameter $mode:
 * - konter|service  → hanya cabang allows_service=true
 * - bengkel|workshop → hanya cabang allows_service=false
 *
 * Owner selalu lolos (scope global).
 */
class CheckBranchType
{
    public function handle(Request $request, Closure $next, string $mode): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Anda belum masuk. Silakan login terlebih dahulu.',
            ], 401);
        }

        // Owner: bypass isolasi tipe cabang.
        if ($user->isOwner()) {
            return $next($request);
        }

        if (! $user->isAdmin()) {
            return response()->json([
                'message' => 'Anda tidak memiliki izin untuk mengakses fitur ini.',
            ], 403);
        }

        if (! $user->branch_id) {
            return response()->json([
                'message' => 'Admin tidak memiliki cabang. Hubungi Owner untuk menugaskan cabang.',
            ], 422);
        }

        $branch = Branch::query()->with('branchType')->find($user->branch_id);
        if (! $branch) {
            return response()->json([
                'message' => 'Cabang admin tidak ditemukan.',
            ], 422);
        }

        $mode = strtolower(trim($mode));
        $isWorkshop = $branch->isWorkshop();

        if (in_array($mode, ['konter', 'service', 'allows_service'], true)) {
            if ($isWorkshop) {
                return response()->json([
                    'message' => 'Modul ini hanya untuk cabang konter. Cabang bengkel tidak diizinkan mengakses servis, closingan, atau gaji konter.',
                ], 403);
            }

            return $next($request);
        }

        if (in_array($mode, ['bengkel', 'workshop'], true)) {
            if (! $isWorkshop) {
                return response()->json([
                    'message' => 'Modul upah bengkel hanya untuk cabang bengkel. Cabang konter tidak diizinkan mengakses modul ini.',
                ], 403);
            }

            return $next($request);
        }

        return response()->json([
            'message' => 'Konfigurasi middleware tipe cabang tidak valid.',
        ], 500);
    }
}
