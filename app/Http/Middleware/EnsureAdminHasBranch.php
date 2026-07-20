<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin tanpa branch_id tidak boleh memakai API (isolasi multi-tenant PRD).
 */
class EnsureAdminHasBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isAdmin() && ! $user->branch_id) {
            return response()->json([
                'message' => 'Admin tidak memiliki cabang. Hubungi Owner untuk menugaskan cabang.',
            ], 422);
        }

        return $next($request);
    }
}
