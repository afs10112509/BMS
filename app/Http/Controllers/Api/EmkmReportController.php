<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalLine;
use App\Services\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmkmReportController extends Controller
{
    public function __construct(
        protected BranchContext $branchContext,
    ) {}

    /**
     * Buku Besar (General Ledger) SAK EMKM
     */
    public function generalLedger(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = JournalLine::with(['account:id,code,name,type', 'journalEntry:id,entry_date,description,invoice_number,branch_id']);

        if (!$user->isOwner()) {
            $query->whereHas('journalEntry', fn($q) => $q->where('branch_id', $user->branch_id));
        } elseif ($request->filled('branch_id')) {
            $query->whereHas('journalEntry', fn($q) => $q->where('branch_id', $request->integer('branch_id')));
        }

        if ($request->filled('account_id')) {
            $query->where('account_id', $request->integer('account_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereHas('journalEntry', fn($q) => $q->whereDate('entry_date', '>=', $request->date('date_from')->toDateString()));
        }

        if ($request->filled('date_to')) {
            $query->whereHas('journalEntry', fn($q) => $q->whereDate('entry_date', '<=', $request->date('date_to')->toDateString()));
        }

        $lines = $query->latest('id')->paginate($request->integer('per_page', 50));

        return response()->json($lines);
    }

    /**
     * Neraca Saldo (Trial Balance) SAK EMKM
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $user->isOwner() ? $request->integer('branch_id') : $user->branch_id;

        $accounts = Account::get()->map(function ($acc) use ($branchId) {
            $query = JournalLine::where('account_id', $acc->id);
            if ($branchId) {
                $query->whereHas('journalEntry', fn($q) => $q->where('branch_id', $branchId));
            }

            $totalDebit = (float) $query->sum('debit');
            $totalCredit = (float) $query->sum('credit');

            return [
                'id' => $acc->id,
                'code' => $acc->code,
                'name' => $acc->name,
                'type' => $acc->type,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'net_balance' => in_array($acc->type, ['aset', 'beban']) ? ($totalDebit - $totalCredit) : ($totalCredit - $totalDebit),
            ];
        });

        return response()->json(['data' => $accounts]);
    }

    /**
     * Laporan Laba Rugi (Income Statement) SAK EMKM
     */
    public function incomeStatement(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $user->isOwner() ? $request->integer('branch_id') : $user->branch_id;

        $salesAccount = Account::where('code', '4-1000')->first();
        $hppAccount = Account::where('code', '5-1000')->first();

        $getSum = function ($accountId, $col) use ($branchId) {
            if (!$accountId) return 0;
            $q = JournalLine::where('account_id', $accountId);
            if ($branchId) $q->whereHas('journalEntry', fn($e) => $e->where('branch_id', $branchId));
            return (float) $q->sum($col);
        };

        $totalPenjualan = $getSum($salesAccount?->id, 'credit') - $getSum($salesAccount?->id, 'debit');
        $totalHpp = $getSum($hppAccount?->id, 'debit') - $getSum($hppAccount?->id, 'credit');
        $labaKotor = $totalPenjualan - $totalHpp;

        return response()->json([
            'data' => [
                'pendapatan_penjualan' => $totalPenjualan,
                'harga_pokok_penjualan' => $totalHpp,
                'laba_kotor' => $labaKotor,
                'beban_operasional' => 0,
                'laba_bersih' => $labaKotor,
            ]
        ]);
    }
}
