<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = BankAccount::with('branch:id,name')->where('is_active', true)->get();
        return response()->json(['data' => $accounts]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:255',
            'account_name' => 'required|string|max:255',
            'current_balance' => 'required|numeric|min:0',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $account = BankAccount::create([
            'bank_name' => trim($data['bank_name']),
            'account_number' => trim($data['account_number']),
            'account_name' => trim($data['account_name']),
            'current_balance' => $data['current_balance'],
            'branch_id' => $data['branch_id'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Rekening / EDC berhasil ditambahkan.',
            'data' => $account,
        ], 201);
    }
}
