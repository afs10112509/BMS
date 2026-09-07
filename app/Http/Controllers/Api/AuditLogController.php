<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->isOwner()) {
            return response()->json(['message' => 'Hanya Owner yang dapat melihat Log BMS.'], 403);
        }

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'action' => ['nullable', 'string', Rule::in(['CREATE', 'UPDATE', 'DELETE'])],
            'module' => ['nullable', 'string', Rule::in(array_keys(AuditLog::TABLE_LABELS))],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $perPage = (int) ($data['per_page'] ?? 20);

        $query = AuditLog::query()
            ->with([
                'user:id,name,email,role',
                'branch:id,name',
            ])
            ->orderByDesc('id');

        if (! empty($data['date_from'])) {
            $query->whereDate('created_at', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('created_at', '<=', $data['date_to']);
        }
        if (! empty($data['user_id'])) {
            $query->where('user_id', (int) $data['user_id']);
        }
        if (! empty($data['branch_id'])) {
            $query->where('branch_id', (int) $data['branch_id']);
        }
        if (! empty($data['action'])) {
            $query->where('action', $data['action']);
        }
        if (! empty($data['module'])) {
            $query->where('table_name', $data['module']);
        }
        if (! empty($data['q'])) {
            $q = trim($data['q']);
            $query->where(function ($builder) use ($q) {
                $builder->where('table_name', 'ilike', "%{$q}%")
                    ->orWhere('action', 'ilike', "%{$q}%")
                    ->orWhereRaw('old_values::text ilike ?', ["%{$q}%"])
                    ->orWhereRaw('new_values::text ilike ?', ["%{$q}%"])
                    ->orWhereHas('user', function ($u) use ($q) {
                        $u->where('name', 'ilike', "%{$q}%")
                            ->orWhere('email', 'ilike', "%{$q}%");
                    })
                    ->orWhereHas('branch', function ($b) use ($q) {
                        $b->where('name', 'ilike', "%{$q}%");
                    });
            });
        }

        $page = $query->paginate($perPage);

        $rows = collect($page->items())->map(function (AuditLog $log) {
            return [
                'id' => $log->id,
                'created_at' => $log->created_at?->timezone(config('app.timezone'))->toIso8601String(),
                'created_at_label' => $log->created_at
                    ? $log->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i')
                    : '—',
                'user_id' => $log->user_id,
                'user_name' => $log->user?->name,
                'user_email' => $log->user?->email,
                'user_role' => $log->user?->role,
                'branch_id' => $log->branch_id,
                'branch_name' => $log->branch?->name,
                'action' => $log->action,
                'action_label' => $log->actionLabel(),
                'module' => $log->table_name,
                'module_label' => $log->moduleLabel(),
                'record_id' => $log->record_id,
                'summary' => $log->summary(),
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
            ];
        })->values();

        $actors = User::query()
            ->whereIn('role', ['owner', 'admin'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return response()->json([
            'message' => 'Log BMS berhasil diambil.',
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'modules' => collect(AuditLog::TABLE_LABELS)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'actions' => collect(AuditLog::ACTION_LABELS)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'users' => $actors,
            ],
        ]);
    }
}
