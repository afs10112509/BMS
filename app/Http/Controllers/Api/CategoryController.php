<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\CategoryAvailability;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(
        protected CategoryAvailability $categoryAvailability,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $includeInactive = $request->boolean('include_inactive', true);

        if ($user->isAdmin()) {
            $categories = $this->categoryAvailability->forBranch(
                (int) $user->branch_id,
                $includeInactive
            );
        } elseif ($request->filled('branch_id')) {
            $categories = $this->categoryAvailability->forBranch(
                $request->integer('branch_id'),
                $includeInactive
            );
        } else {
            $query = Category::query()
                ->with('branch:id,name')
                ->orderBy('type')
                ->orderBy('name');

            if (! $includeInactive) {
                $query->active();
            }

            $categories = $query->get();
        }

        return response()->json([
            'message' => 'Daftar kategori berhasil diambil.',
            'data' => $categories,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:income,expense'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        if ($user->isAdmin()) {
            if (! $user->branch_id) {
                return response()->json([
                    'message' => 'Admin cabang tidak memiliki cabang.',
                ], 422);
            }

            if (array_key_exists('branch_id', $data) && $data['branch_id'] !== null
                && (int) $data['branch_id'] !== (int) $user->branch_id) {
                return response()->json([
                    'message' => 'Admin cabang hanya boleh membuat kategori lokal cabangnya sendiri.',
                ], 403);
            }

            if (array_key_exists('branch_id', $data) && $data['branch_id'] === null) {
                return response()->json([
                    'message' => 'Admin cabang hanya boleh membuat kategori lokal cabangnya sendiri.',
                ], 403);
            }

            $data['branch_id'] = (int) $user->branch_id;
        } elseif ($user->isOwner()) {
            $data['branch_id'] = $data['branch_id'] ?? null;
        } else {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        try {
            $category = Category::query()->create([
                'branch_id' => $data['branch_id'],
                'name' => $data['name'],
                'type' => $data['type'],
                'is_active' => true,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return response()->json([
                    'message' => 'Kategori dengan nama dan tipe ini sudah ada. Aktifkan kembali jika sebelumnya dinonaktifkan.',
                ], 422);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'Kategori berhasil dibuat.',
            'data' => $category->load('branch:id,name'),
        ], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $user = $request->user();

        if ($category->isSystem()) {
            return response()->json([
                'message' => 'Kategori sistem tidak boleh diubah.',
            ], 422);
        }

        if ($user->isAdmin()) {
            if ($category->branch_id === null
                || (int) $category->branch_id !== (int) $user->branch_id) {
                return response()->json([
                    'message' => 'Anda tidak boleh mengubah kategori global atau cabang lain.',
                ], 403);
            }
        } elseif (! $user->isOwner()) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        // Owner boleh ubah cakupan global ↔ lokal.
        if ($user->isOwner()) {
            $rules['branch_id'] = ['sometimes', 'nullable', 'integer', 'exists:branches,id'];
        }

        $data = $request->validate($rules);

        if ($user->isAdmin()) {
            unset($data['branch_id']);
        }

        try {
            $category->fill($data);
            $category->save();
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return response()->json([
                    'message' => 'Kategori dengan nama dan tipe ini sudah ada di cakupan tersebut.',
                ], 422);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'Kategori berhasil diperbarui.',
            'data' => $category->fresh()->load('branch:id,name'),
        ]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $user = $request->user();

        if ($category->isSystem()) {
            return response()->json([
                'message' => 'Kategori sistem tidak boleh dihapus.',
            ], 422);
        }

        if ($user->isAdmin()) {
            if ($category->branch_id === null
                || (int) $category->branch_id !== (int) $user->branch_id) {
                return response()->json([
                    'message' => 'Anda tidak boleh menghapus kategori global atau cabang lain.',
                ], 403);
            }
        } elseif (! $user->isOwner()) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($category->transactions()->exists()) {
            return response()->json([
                'message' => 'Kategori sudah dipakai di transaksi. Nonaktifkan saja, jangan dihapus.',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => 'Kategori berhasil dihapus.',
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'categories_local_unique')
            || str_contains($message, 'categories_global_unique')
            || str_contains($message, 'UNIQUE constraint failed')
            || ($e->errorInfo[0] ?? null) === '23505';
    }
}
