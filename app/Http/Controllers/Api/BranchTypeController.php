<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BranchTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BranchType::query()->orderBy('sort_order')->orderBy('name');

        if (! $request->boolean('all') || ! $request->user()?->isOwner()) {
            $query->where('status', 'active');
        }

        if ($request->user()?->isOwner()) {
            $query->with(['accounts' => fn ($q) => $q->orderBy('sort_order')->orderBy('name')]);
        }

        return response()->json([
            'message' => 'Daftar tipe cabang berhasil diambil.',
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $type = BranchType::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'allows_service' => $data['allows_service'] ?? true,
            'status' => $data['status'] ?? 'active',
            'sort_order' => $data['sort_order'] ?? ((int) BranchType::query()->max('sort_order') + 1),
        ]);

        return response()->json([
            'message' => 'Tipe cabang berhasil ditambahkan.',
            'data' => $type,
        ], 201);
    }

    public function update(Request $request, BranchType $branchType): JsonResponse
    {
        $data = $this->validated($request, $branchType);

        // Kode tidak diubah setelah dibuat agar cabang yang memakai code tetap valid
        unset($data['code']);

        $branchType->fill($data);
        $branchType->save();

        return response()->json([
            'message' => 'Tipe cabang berhasil diperbarui.',
            'data' => $branchType->fresh(),
        ]);
    }

    public function destroy(BranchType $branchType): JsonResponse
    {
        if ($branchType->branches()->exists()) {
            return response()->json([
                'message' => 'Tipe tidak dapat dihapus karena masih dipakai cabang. Nonaktifkan saja.',
            ], 422);
        }

        if (in_array($branchType->code, ['konter', 'bengkel'], true)) {
            return response()->json([
                'message' => 'Tipe bawaan tidak dapat dihapus. Nonaktifkan jika tidak dipakai.',
            ], 422);
        }

        $branchType->delete();

        return response()->json([
            'message' => 'Tipe cabang berhasil dihapus.',
        ]);
    }

    public function syncAccounts(Request $request, BranchType $branchType): JsonResponse
    {
        $data = $request->validate([
            'account_ids' => ['present', 'array'],
            'account_ids.*' => ['integer', 'exists:accounts,id'],
        ]);

        $branchType->accounts()->sync($data['account_ids']);

        return response()->json([
            'message' => 'Akun untuk tipe cabang berhasil disimpan.',
            'data' => $branchType->fresh()->load(['accounts' => fn ($q) => $q->orderBy('sort_order')->orderBy('name')]),
        ]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?BranchType $existing = null): array
    {
        $codeRule = [
            $existing ? 'sometimes' : 'required',
            'string',
            'max:50',
            'regex:/^[a-z][a-z0-9_]*$/',
            Rule::unique('branch_types', 'code')->ignore($existing?->id),
        ];

        $data = $request->validate([
            'code' => $codeRule,
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:100'],
            'allows_service' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);

        if (isset($data['code'])) {
            $data['code'] = Str::lower($data['code']);
        }

        if ($request->has('allows_service')) {
            $data['allows_service'] = $request->boolean('allows_service');
        }

        return $data;
    }
}
