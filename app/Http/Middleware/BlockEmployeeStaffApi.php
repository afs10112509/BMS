<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Karyawan (role employee) tidak boleh mengakses API staf/keuangan.
 */
class BlockEmployeeStaffApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->role === 'employee') {
            return response()->json([
                'message' => 'Akun karyawan hanya dapat mengakses fitur absensi.',
            ], 403);
        }

        return $next($request);
    }
}
