<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdjustmentController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BranchTypeController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CashflowController;
use App\Http\Controllers\Api\CashflowWorkbookController;
use App\Http\Controllers\Api\ClosingBoardController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DatabaseBackupController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\OpeningBalanceController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PeriodLockController;
use App\Http\Controllers\Api\ProfitShareController;
use App\Http\Controllers\Api\PulsaProfitController;
use App\Http\Controllers\Api\BrilinkController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SelfAttendanceController;
use App\Http\Controllers\Api\ServiceRecordController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\WorkshopWageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'API Multi-Branch Financial Tracker siap digunakan.',
        'versi' => app()->version(),
        'ui' => url('/app/'),
        'endpoint' => [
            'auth' => [
                'POST /api/auth/login',
                'GET /api/auth/demo-accounts',
                'POST /api/auth/logout',
                'GET /api/auth/me',
                'POST /api/auth/confirm-password',
                'PUT /api/auth/profile',
            ],
            'cabang' => ['GET /api/branches', 'POST /api/branches', 'PUT /api/branches/{id}'],
            'admin' => ['GET /api/admins', 'POST /api/admins', 'PUT /api/admins/{id}', 'DELETE /api/admins/{id}'],
            'kategori' => ['GET /api/categories', 'POST /api/categories', 'PUT /api/categories/{id}', 'DELETE /api/categories/{id}'],
            'akun' => ['GET /api/accounts'],
            'transaksi' => [
                'GET /api/transactions',
                'POST /api/transactions',
                'PUT /api/transactions/{id}',
                'DELETE /api/transactions/{id}',
            ],
            'transfer' => [
                'POST /api/transfers/internal',
                'POST /api/transfers/inter-branch/request',
                'POST /api/transfers/inter-branch/{id}/approve',
                'POST /api/transfers/inter-branch/{id}/reject',
            ],
            'kontrol_finansial' => [
                'POST /api/period-locks',
                'POST /api/reconciliations',
                'POST /api/adjustments',
            ],
            'dasbor' => ['GET /api/dashboard/owner', 'GET /api/dashboard/branch'],
        ],
        'auth_hint' => [
            'method' => 'POST',
            'url' => url('/api/auth/login'),
            'body' => [
                'email' => 'string',
                'password' => 'string',
            ],
        ],
    ]);
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/demo-accounts', [AuthController::class, 'demoAccounts']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/confirm-password', [AuthController::class, 'confirmPassword']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
    });
});

// Absensi mandiri karyawan (+ tinjau Owner/PIC + setting jam Owner)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/attendance/self/today', [SelfAttendanceController::class, 'today']);
    Route::get('/attendance/self/history', [SelfAttendanceController::class, 'history']);
    Route::post('/attendance/self/check-in', [SelfAttendanceController::class, 'checkIn']);
    Route::post('/attendance/self/check-out', [SelfAttendanceController::class, 'checkOut']);
    Route::post('/attendance/self/leave-sick', [SelfAttendanceController::class, 'leaveOrSick']);
    Route::get('/attendance/self/photos/{attendance}/{side}', [SelfAttendanceController::class, 'photo'])
        ->whereIn('side', ['in', 'out']);

    Route::get('/attendance/reviews', [SelfAttendanceController::class, 'pendingReviews']);
    Route::post('/attendance/reviews/{attendance}', [SelfAttendanceController::class, 'review']);

    Route::get('/attendance/settings', [SelfAttendanceController::class, 'getSettings'])->middleware('role:owner');
    Route::put('/attendance/settings', [SelfAttendanceController::class, 'saveSettings'])->middleware('role:owner');

    // Laporan: Owner (semua jenis) + PIC bengkel (upah saja) — otorisasi di ReportController.
    Route::get('/reports/{type}', [ReportController::class, 'show']);
    Route::get('/reports/{type}/pdf-link', [ReportController::class, 'pdfLink']);
    Route::get('/reports/{type}/pdf', [ReportController::class, 'pdf']);

    // Keuntungan pulsa: Admin/PIC konter (mutasi) + Owner (pantau). Di luar block.employee agar PIC bisa akses.
    Route::middleware('branch.type:konter')->group(function () {
        Route::get('/pulsa-profits/providers', [PulsaProfitController::class, 'providers']);
        Route::post('/pulsa-profits/providers', [PulsaProfitController::class, 'storeProvider']);
        Route::put('/pulsa-profits/providers/{pulsaProvider}', [PulsaProfitController::class, 'updateProvider']);
        Route::delete('/pulsa-profits/providers/{pulsaProvider}', [PulsaProfitController::class, 'destroyProvider']);
        Route::get('/pulsa-profits', [PulsaProfitController::class, 'index']);
        Route::get('/pulsa-profits/daily', [PulsaProfitController::class, 'daily']);
        Route::put('/pulsa-profits/daily', [PulsaProfitController::class, 'upsertDaily']);
        Route::delete('/pulsa-profits/{pulsaDailySheet}', [PulsaProfitController::class, 'destroy']);
    });

    // Brilink harian: semua cabang — Admin/PIC mutasi, Owner pantau. Belum otomatis ke kas.
    Route::get('/brilink', [BrilinkController::class, 'index']);
    Route::get('/brilink/daily', [BrilinkController::class, 'daily']);
    Route::post('/brilink/daily/copy-previous', [BrilinkController::class, 'copyPrevious']);
    Route::put('/brilink/daily', [BrilinkController::class, 'upsertDaily']);
    Route::delete('/brilink/{brilinkDailySheet}', [BrilinkController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'admin.branch', 'block.employee'])->group(function () {
    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/branches', [BranchController::class, 'store'])->middleware('role:owner');
    Route::put('/branches/{branch}', [BranchController::class, 'update'])->middleware('role:owner');

    Route::get('/branch-types', [BranchTypeController::class, 'index']);
    Route::post('/branch-types', [BranchTypeController::class, 'store'])->middleware('role:owner');
    Route::put('/branch-types/{branchType}', [BranchTypeController::class, 'update'])->middleware('role:owner');
    Route::delete('/branch-types/{branchType}', [BranchTypeController::class, 'destroy'])->middleware('role:owner');
    Route::put('/branch-types/{branchType}/accounts', [BranchTypeController::class, 'syncAccounts'])->middleware('role:owner');

    Route::get('/branches/{branch}/account-settings', [BranchController::class, 'accountSettings'])->middleware('role:owner');
    Route::put('/branches/{branch}/accounts', [BranchController::class, 'syncAccounts'])->middleware('role:owner');

    Route::get('/admins', [AdminController::class, 'index'])->middleware('role:owner');
    Route::post('/admins', [AdminController::class, 'store'])->middleware('role:owner');
    Route::put('/admins/{admin}', [AdminController::class, 'update'])->middleware('role:owner');
    Route::delete('/admins/{admin}', [AdminController::class, 'destroy'])->middleware('role:owner');

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    Route::get('/accounts', [AccountController::class, 'index']);
    Route::post('/accounts', [AccountController::class, 'store'])->middleware('role:owner,admin');
    Route::put('/accounts/{account}', [AccountController::class, 'update'])->middleware('role:owner');
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->middleware('role:owner');

    Route::get('/opening-balances', [OpeningBalanceController::class, 'index']);
    Route::put('/opening-balances', [OpeningBalanceController::class, 'upsert']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::post('/transactions/batch', [TransactionController::class, 'storeBatch']);
    Route::put('/transactions/{transaction}', [TransactionController::class, 'update']);
    Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy']);

    Route::post('/transfers/internal', [TransferController::class, 'internal']);
    Route::post('/transfers/inter-branch/request', [TransferController::class, 'requestInterBranch']);
    Route::post('/transfers/inter-branch/{transfer}/approve', [TransferController::class, 'approve'])
        ->middleware('role:owner');
    Route::post('/transfers/inter-branch/{transfer}/reject', [TransferController::class, 'reject'])
        ->middleware('role:owner');

    Route::get('/period-locks', [PeriodLockController::class, 'index'])->middleware('role:owner');
    Route::post('/period-locks', [PeriodLockController::class, 'store'])->middleware('role:owner');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('role:owner');
    Route::get('/reconciliations', [ReconciliationController::class, 'index']);
    Route::post('/reconciliations', [ReconciliationController::class, 'store']);
    Route::post('/adjustments', [AdjustmentController::class, 'store'])->middleware('role:owner');

    Route::get('/dashboard/owner', [DashboardController::class, 'owner'])->middleware('role:owner');
    Route::get('/dashboard/branch', [DashboardController::class, 'branch']);

    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('role:owner,admin');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('role:owner,admin');
    Route::put('/employees/{employee}/account', [EmployeeController::class, 'upsertAccount'])->middleware('role:owner');
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('role:owner');

    Route::get('/attendance/daily', [AttendanceController::class, 'daily']);
    Route::put('/attendance/daily', [AttendanceController::class, 'upsertDaily']);
    Route::get('/attendance/board', [AttendanceController::class, 'board']);
    Route::put('/attendance/cell', [AttendanceController::class, 'upsertCell'])->middleware('role:owner');

    // Konter: allows_service=true (admin bengkel → 403; owner lolos)
    Route::middleware('branch.type:konter')->group(function () {
        Route::get('/closings/board', [ClosingBoardController::class, 'board']);
        Route::put('/closings/targets', [ClosingBoardController::class, 'upsertTarget']);
        Route::put('/closings/daily', [ClosingBoardController::class, 'upsertDaily']);
        Route::post('/closings/lock', [ClosingBoardController::class, 'lock'])->middleware('role:owner');
        Route::post('/closings/unlock', [ClosingBoardController::class, 'unlock'])->middleware('role:owner');

        Route::get('/service-records/technicians', [ServiceRecordController::class, 'technicians']);
        Route::get('/service-records', [ServiceRecordController::class, 'index']);
        Route::post('/service-records/batch', [ServiceRecordController::class, 'storeBatch']);
        Route::post('/service-records', [ServiceRecordController::class, 'store']);
        Route::put('/service-records/{serviceRecord}', [ServiceRecordController::class, 'update']);
        Route::delete('/service-records/{serviceRecord}', [ServiceRecordController::class, 'destroy']);
    });

    // Gaji konter: owner only (admin mana pun tidak akses — lebih ketat dari isolasi tipe)
    Route::middleware('role:owner')->group(function () {
        Route::get('/payrolls/board', [PayrollController::class, 'board']);
        Route::put('/payrolls/save', [PayrollController::class, 'save']);
        Route::post('/payrolls/recalculate', [PayrollController::class, 'recalculate']);
        Route::post('/payrolls/lock', [PayrollController::class, 'lock']);
        Route::post('/payrolls/unlock', [PayrollController::class, 'unlock']);
        Route::post('/payrolls/mark-paid', [PayrollController::class, 'markPaid']);
        Route::post('/payrolls/mark-unpaid', [PayrollController::class, 'markUnpaid']);
        Route::get('/payrolls/detail', [PayrollController::class, 'detail']);

        // Bagi hasil PIC per cabang (tahap 1: input manual)
        Route::get('/profit-shares/board', [ProfitShareController::class, 'board']);
        Route::get('/profit-shares/detail', [ProfitShareController::class, 'detail']);
        Route::put('/profit-shares/save', [ProfitShareController::class, 'save']);
        Route::post('/profit-shares/lock', [ProfitShareController::class, 'lock']);
        Route::post('/profit-shares/unlock', [ProfitShareController::class, 'unlock']);
        Route::post('/profit-shares/copy-previous', [ProfitShareController::class, 'copyPrevious']);

        Route::get('/system/database-backups', [DatabaseBackupController::class, 'index']);
        Route::put('/system/database-backups/schedule', [DatabaseBackupController::class, 'updateSchedule']);
        Route::post('/system/database-backups', [DatabaseBackupController::class, 'store'])
            ->middleware('throttle:3,10');
        Route::post('/system/database-backups/upload', [DatabaseBackupController::class, 'upload'])
            ->middleware('throttle:3,10');
        Route::get('/system/database-backups/{file}/download', [DatabaseBackupController::class, 'download'])
            ->where('file', 'bms_db_[0-9]{8}_[0-9]{6}\\.dump');
        Route::post('/system/database-backups/{file}/restore', [DatabaseBackupController::class, 'restore'])
            ->where('file', 'bms_db_[0-9]{8}_[0-9]{6}\\.dump')
            ->middleware('throttle:2,30');
        Route::get('/system/database-backups/{file}/download-link', [DatabaseBackupController::class, 'downloadLink'])
            ->where('file', 'bms_db_[0-9]{8}_[0-9]{6}\\.dump');

        Route::get('/system/reminders', [ReminderController::class, 'index']);
        Route::post('/system/reminders/closing/send', [ReminderController::class, 'sendClosing'])
            ->middleware('throttle:5,10');
        Route::put('/system/reminders/{type}', [ReminderController::class, 'update'])
            ->where('type', 'closing');
    });

    // Bengkel: allows_service=false (admin konter → 403; owner lolos)
    Route::middleware('branch.type:bengkel')->group(function () {
        Route::get('/workshop-wages/settings', [WorkshopWageController::class, 'getSettings']);
        Route::put('/workshop-wages/settings', [WorkshopWageController::class, 'upsertSettings']);
        Route::post('/workshop-wages/settings/copy-previous', [WorkshopWageController::class, 'copyPreviousSettings']);
        Route::get('/workshop-wages/jobs', [WorkshopWageController::class, 'jobs']);
        Route::post('/workshop-wages/jobs', [WorkshopWageController::class, 'storeJob']);
        Route::post('/workshop-wages/jobs/batch', [WorkshopWageController::class, 'storeJobsBatch']);
        Route::put('/workshop-wages/jobs/{workshopJob}', [WorkshopWageController::class, 'updateJob']);
        Route::delete('/workshop-wages/jobs/{workshopJob}', [WorkshopWageController::class, 'destroyJob']);
        Route::get('/workshop-wages/weeks', [WorkshopWageController::class, 'weeks']);
        Route::get('/workshop-wages/weeks/detail', [WorkshopWageController::class, 'weekDetail']);
        Route::post('/workshop-wages/weeks/pay', [WorkshopWageController::class, 'payWeek']);
        Route::post('/workshop-wages/weeks/reopen', [WorkshopWageController::class, 'reopenWeek'])->middleware('role:owner');
        Route::get('/workshop-wages/technicians', [WorkshopWageController::class, 'technicians']);
        Route::get('/workshop-wages/job-types', [WorkshopWageController::class, 'jobTypes']);
        Route::post('/workshop-wages/job-types', [WorkshopWageController::class, 'storeJobType']);
        Route::put('/workshop-wages/job-types/{workshopJobType}', [WorkshopWageController::class, 'updateJobType']);
        Route::delete('/workshop-wages/job-types/{workshopJobType}', [WorkshopWageController::class, 'destroyJobType']);
    });

    // Detail transaksi per pos (laporan Alur Kas Bulanan) — Owner + Admin bengkel.
    Route::get('/cashflow/monthly', [CashflowController::class, 'monthly']);
    Route::get('/cashflow/matrix', [CashflowController::class, 'matrix']);
    Route::get('/cashflow/category-transactions', [CashflowController::class, 'categoryTransactions']);

    // Workbook Alur Kas (edit snapshot) — Owner only.
    Route::middleware('role:owner')->group(function () {
        Route::get('/cashflow/workbook', [CashflowWorkbookController::class, 'board']);
        Route::put('/cashflow/workbook/save', [CashflowWorkbookController::class, 'save']);
        Route::post('/cashflow/workbook/seed', [CashflowWorkbookController::class, 'seedFromSystem']);
        Route::post('/cashflow/workbook/copy-previous', [CashflowWorkbookController::class, 'copyPrevious']);
    });
    // ==========================================
    // INVENTORY + POS
    // ==========================================
    Route::get("/suppliers", [SupplierController::class, "index"]);
    Route::post("/suppliers", [SupplierController::class, "store"])->middleware("role:owner");
    Route::put("/suppliers/{supplier}", [SupplierController::class, "update"])->middleware("role:owner");
    Route::delete("/suppliers/{supplier}", [SupplierController::class, "destroy"])->middleware("role:owner");

    Route::get("/products", [ProductController::class, "index"]);
    Route::get("/products/low-stock", [ProductController::class, "lowStock"]);
    Route::post("/products", [ProductController::class, "store"]);
    Route::put("/products/{product}", [ProductController::class, "update"]);
    Route::post("/products/{product}/adjust-stock", [ProductController::class, "adjustStock"]);
    Route::delete("/products/{product}", [ProductController::class, "destroy"])->middleware("role:owner");

    Route::get("/sales", [SaleController::class, "index"]);
    Route::get("/sales/daily-summary", [SaleController::class, "dailySummary"]);
    Route::post("/sales", [SaleController::class, "store"]);
    Route::get("/sales/{sale}", [SaleController::class, "show"]);
    Route::post("/sales/{sale}/cancel", [SaleController::class, "cancel"]);

    // IMEI & Serial Numbers
    Route::get("/product-serials", [\App\Http\Controllers\Api\ProductSerialController::class, "index"]);
    Route::post("/product-serials", [\App\Http\Controllers\Api\ProductSerialController::class, "store"]);

    // Stock Transfers antar cabang
    Route::get("/stock-transfers", [\App\Http\Controllers\Api\StockTransferController::class, "index"]);
    Route::post("/stock-transfers", [\App\Http\Controllers\Api\StockTransferController::class, "store"]);
    Route::post("/stock-transfers/{stockTransfer}/approve", [\App\Http\Controllers\Api\StockTransferController::class, "approve"]);
    Route::post("/stock-transfers/{stockTransfer}/receive", [\App\Http\Controllers\Api\StockTransferController::class, "receive"]);
    Route::post("/stock-transfers/{stockTransfer}/reject", [\App\Http\Controllers\Api\StockTransferController::class, "reject"]);

    // Customer Management & Loyalty Points
    Route::get("/customers", [\App\Http\Controllers\Api\CustomerController::class, "index"]);
    Route::post("/customers", [\App\Http\Controllers\Api\CustomerController::class, "store"]);
    Route::get("/customers/{customer}", [\App\Http\Controllers\Api\CustomerController::class, "show"]);
    Route::put("/customers/{customer}", [\App\Http\Controllers\Api\CustomerController::class, "update"]);
    Route::post("/customers/{customer}/points", [\App\Http\Controllers\Api\CustomerController::class, "addPoints"]);

    // Employee Points & Commission (10k = 1 pt = 1k bonus)
    Route::get("/employee-points", [\App\Http\Controllers\Api\EmployeePointController::class, "index"]);
    Route::post("/employee-points", [\App\Http\Controllers\Api\EmployeePointController::class, "store"]);
    Route::get("/employee-points/summary", [\App\Http\Controllers\Api\EmployeePointController::class, "summary"]);

    // Laporan Akuntansi SAK EMKM
    Route::get("/reports/emkm/general-ledger", [\App\Http\Controllers\Api\EmkmReportController::class, "generalLedger"]);
    Route::get("/reports/emkm/trial-balance", [\App\Http\Controllers\Api\EmkmReportController::class, "trialBalance"]);
    Route::get("/reports/emkm/income-statement", [\App\Http\Controllers\Api\EmkmReportController::class, "incomeStatement"]);

    // Brands / Merk
    Route::get("/brands", [\App\Http\Controllers\Api\BrandController::class, "index"]);
    Route::post("/brands", [\App\Http\Controllers\Api\BrandController::class, "store"]);

    // Suppliers
    Route::get("/suppliers", [\App\Http\Controllers\Api\SupplierController::class, "index"]);
    Route::post("/suppliers", [\App\Http\Controllers\Api\SupplierController::class, "store"]);

    // Tarif & Jenis Servis
    Route::get("/service-types", [\App\Http\Controllers\Api\ServiceTypeController::class, "index"]);
    Route::post("/service-types", [\App\Http\Controllers\Api\ServiceTypeController::class, "store"]);

    // Rekening Bank & EDC
    Route::get("/bank-accounts", [\App\Http\Controllers\Api\BankAccountController::class, "index"]);
    Route::post("/bank-accounts", [\App\Http\Controllers\Api\BankAccountController::class, "store"]);

});

Route::get('/reports/{type}/pdf-file', [ReportController::class, 'pdfFile'])
    ->name('api.reports.pdf-file')
    ->middleware('signed');

Route::get('/system/database-backups/file/{file}', [DatabaseBackupController::class, 'file'])
    ->name('api.system.database-backups.file')
    ->where('file', 'bms_db_[0-9]{8}_[0-9]{6}\\.dump')
    ->middleware('signed');
