<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PeriodLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeriodLockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PeriodLock::query()
            ->with([
                'branch:id,name,type',
                'branch.branchType:id,code,name,allows_service,status',
                'lockedBy:id,name',
            ])
            ->orderByDesc('period')
            ->orderBy('branch_id');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->boolean('locked_only')) {
            $query->where('is_locked', true);
        }

        return response()->json([
            'message' => 'Daftar kunci periode berhasil diambil.',
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'is_locked' => ['required', 'boolean'],
        ]);

        $lock = PeriodLock::query()->updateOrCreate(
            [
                'branch_id' => $data['branch_id'],
                'period' => $data['period'],
            ],
            [
                'is_locked' => $data['is_locked'],
                'locked_by' => $data['is_locked'] ? $request->user()->id : null,
            ]
        );

        $status = $data['is_locked'] ? 'dikunci' : 'dibuka';

        return response()->json([
            'message' => "Periode {$data['period']} berhasil {$status}.",
            'data' => $lock->load([
                'branch.branchType',
                'lockedBy:id,name',
            ]),
        ]);
    }
}
