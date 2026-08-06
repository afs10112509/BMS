<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function index(): JsonResponse
    {
        $admins = User::query()
            ->with([
                'branch:id,name,type',
                'branch.branchType:id,code,name,allows_service,status',
            ])
            ->where('role', 'admin')
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'email', 'role', 'created_at']);

        return response()->json([
            'message' => 'Daftar admin cabang berhasil diambil.',
            'data' => $admins,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $admin = User::query()->create([
            'branch_id' => $data['branch_id'],
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        return response()->json([
            'message' => 'Admin cabang berhasil dibuat.',
            'data' => $admin->load(['branch:id,name,type', 'branch.branchType:id,code,name,allows_service,status']),
        ], 201);
    }

    public function update(Request $request, User $admin): JsonResponse
    {
        if ($admin->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya akun admin cabang yang dapat diubah di sini.',
            ], 422);
        }

        $data = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($admin->id)],
            'password' => ['nullable', 'string', 'min:6'],
        ]);

        if (array_key_exists('password', $data) && filled($data['password'])) {
            $admin->password = $data['password'];
        }
        unset($data['password']);

        $admin->fill($data);
        $admin->save();

        return response()->json([
            'message' => 'Admin cabang berhasil diperbarui.',
            'data' => $admin->fresh()->load(['branch:id,name,type', 'branch.branchType:id,code,name,allows_service,status']),
        ]);
    }

    public function destroy(User $admin): JsonResponse
    {
        if ($admin->role !== 'admin') {
            return response()->json([
                'message' => 'Hanya akun admin cabang yang dapat dihapus di sini.',
            ], 422);
        }

        $blockers = [];
        if ($admin->transactions()->exists()) {
            $blockers[] = 'transaksi';
        }
        if ($admin->reconciliations()->exists()) {
            $blockers[] = 'rekonsiliasi';
        }
        if ($admin->serviceRecords()->exists()) {
            $blockers[] = 'catatan servis';
        }
        if ($admin->requestedTransfers()->exists()) {
            $blockers[] = 'transfer antar cabang';
        }

        if ($blockers !== []) {
            return response()->json([
                'message' => 'Admin tidak dapat dihapus karena masih punya data terkait: '.implode(', ', $blockers).'.',
            ], 422);
        }

        $admin->tokens()->delete();
        $admin->delete();

        return response()->json([
            'message' => 'Admin cabang berhasil dihapus.',
        ]);
    }
}
