<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Satu pintu resolusi cabang untuk isolasi multi-tenant (PRD §3).
 */
class BranchContext
{
    /**
     * Admin wajib punya branch_id. Owner tidak kena aturan ini.
     */
    public function assertAdminHasBranch(User $user): ?JsonResponse
    {
        if ($user->isAdmin() && ! $user->branch_id) {
            return response()->json([
                'message' => 'Admin tidak memiliki cabang. Hubungi Owner untuk menugaskan cabang.',
            ], 422);
        }

        return null;
    }

    /**
     * Resolve branch untuk operasi scoped.
     * Admin: selalu user.branch_id (abaikan request — cegah privilege escalation).
     * Owner: pakai requested; wajib jika $requireBranch.
     *
     * @return int|null|JsonResponse
     */
    public function resolve(User $user, ?int $requestedBranchId, bool $requireBranch = true)
    {
        if ($deny = $this->assertAdminHasBranch($user)) {
            return $deny;
        }

        if ($user->isAdmin()) {
            return (int) $user->branch_id;
        }

        if ($user->isOwner()) {
            if ($requireBranch && ! $requestedBranchId) {
                return response()->json([
                    'message' => 'Cabang wajib dipilih.',
                ], 422);
            }

            return $requestedBranchId ? (int) $requestedBranchId : null;
        }

        return response()->json(['message' => 'Akses ditolak.'], 403);
    }

    /**
     * Resolve cabang bengkel saja (allows_service = false).
     *
     * @return int|JsonResponse
     */
    public function resolveWorkshop(User $user, ?int $requestedBranchId, bool $requireBranch = true)
    {
        if ($deny = $this->assertAdminHasBranch($user)) {
            return $deny;
        }

        if ($user->isAdmin()) {
            $branch = Branch::query()->with('branchType')->find($user->branch_id);
            if (! $branch || ! $branch->isWorkshop()) {
                return response()->json([
                    'message' => 'Modul upah bengkel hanya untuk cabang bengkel.',
                ], 403);
            }

            return (int) $user->branch_id;
        }

        if ($user->isOwner()) {
            if ($requireBranch && ! $requestedBranchId) {
                return response()->json([
                    'message' => 'Cabang bengkel wajib dipilih.',
                ], 422);
            }

            if (! $requestedBranchId) {
                return response()->json([
                    'message' => 'Cabang bengkel wajib dipilih.',
                ], 422);
            }

            $branch = Branch::query()->with('branchType')->find($requestedBranchId);
            if (! $branch || ! $branch->isWorkshop()) {
                return response()->json([
                    'message' => 'Cabang harus bertipe bengkel.',
                ], 422);
            }

            return (int) $requestedBranchId;
        }

        return response()->json(['message' => 'Akses ditolak.'], 403);
    }

    /**
     * Pastikan admin hanya menyentuh resource milik cabangnya.
     */
    public function ownsBranch(User $user, int $branchId): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        return $user->isAdmin()
            && $user->branch_id
            && (int) $user->branch_id === (int) $branchId;
    }

    public function denyUnlessOwnsBranch(User $user, int $branchId): ?JsonResponse
    {
        if ($this->ownsBranch($user, $branchId)) {
            return null;
        }

        return response()->json([
            'message' => 'Anda tidak boleh mengakses data cabang lain.',
        ], 403);
    }
}
