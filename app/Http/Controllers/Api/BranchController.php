<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\AccountAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function __construct(private AccountAvailability $availability)
    {
    }

    public function index(): JsonResponse
    {
        $branches = Branch::query()
            ->with('branchType:id,code,name,allows_service,status')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'address', 'status']);

        return response()->json([
            'message' => 'Daftar cabang berhasil diambil.',
            'data' => $branches,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::exists('branch_types', 'code')->where('status', 'active')],
            'address' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $branch = Branch::query()->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'address' => $data['address'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->json([
            'message' => 'Cabang berhasil dibuat.',
            'data' => $branch->load('branchType'),
        ], 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::exists('branch_types', 'code')->where('status', 'active')],
            'address' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $branch->update($data);

        return response()->json([
            'message' => 'Cabang berhasil diperbarui.',
            'data' => $branch->fresh()->load('branchType'),
        ]);
    }

    public function accountSettings(Branch $branch): JsonResponse
    {
        return response()->json([
            'message' => 'Pengaturan akun cabang berhasil diambil.',
            'data' => $this->availability->settingsForBranch($branch),
        ]);
    }

    public function syncAccounts(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['type', 'custom'])],
            'account_ids' => ['required_if:mode,custom', 'array'],
            'account_ids.*' => ['integer', 'exists:accounts,id'],
        ]);

        if ($data['mode'] === 'type') {
            $branch->accounts()->detach();
        } else {
            $branch->accounts()->sync($data['account_ids'] ?? []);
        }

        return response()->json([
            'message' => 'Akun cabang berhasil disimpan.',
            'data' => $this->availability->settingsForBranch($branch->fresh()),
        ]);
    }
}
