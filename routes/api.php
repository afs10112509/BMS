<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdjustmentController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BranchTypeController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ClosingBoardController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\OpeningBalanceController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PeriodLockController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\ReportController;
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

Route::middleware(['auth:sanctum', 'admin.branch'])->group(function () {
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
    Route::post('/reconciliations', [ReconciliationController::class, 'store']);
    Route::post('/adjustments', [AdjustmentController::class, 'store'])->middleware('role:owner');

    Route::get('/dashboard/owner', [DashboardController::class, 'owner'])->middleware('role:owner');
    Route::get('/dashboard/branch', [DashboardController::class, 'branch']);

    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('role:owner');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('role:owner');
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

        Route::get('/service-records', [ServiceRecordController::class, 'index']);
        Route::post('/service-records', [ServiceRecordController::class, 'store']);
        Route::put('/service-records/{serviceRecord}', [ServiceRecordController::class, 'update']);
        Route::delete('/service-records/{serviceRecord}', [ServiceRecordController::class, 'destroy']);
    });

    // Gaji konter: owner only (admin mana pun tidak akses — lebih ketat dari isolasi tipe)
    Route::middleware('role:owner')->group(function () {
        Route::get('/payrolls/board', [PayrollController::class, 'board']);
        Route::put('/payrolls/save', [PayrollController::class, 'save']);
        Route::post('/payrolls/lock', [PayrollController::class, 'lock']);
        Route::post('/payrolls/unlock', [PayrollController::class, 'unlock']);
        Route::get('/payrolls/detail', [PayrollController::class, 'detail']);
    });

    // Bengkel: allows_service=false (admin konter → 403; owner lolos)
    Route::middleware('branch.type:bengkel')->group(function () {
        Route::get('/workshop-wages/settings', [WorkshopWageController::class, 'getSettings']);
        Route::put('/workshop-wages/settings', [WorkshopWageController::class, 'upsertSettings']);
        Route::get('/workshop-wages/jobs', [WorkshopWageController::class, 'jobs']);
        Route::post('/workshop-wages/jobs', [WorkshopWageController::class, 'storeJob']);
        Route::put('/workshop-wages/jobs/{workshopJob}', [WorkshopWageController::class, 'updateJob']);
        Route::delete('/workshop-wages/jobs/{workshopJob}', [WorkshopWageController::class, 'destroyJob']);
        Route::get('/workshop-wages/weeks', [WorkshopWageController::class, 'weeks']);
        Route::get('/workshop-wages/weeks/detail', [WorkshopWageController::class, 'weekDetail']);
        Route::post('/workshop-wages/weeks/pay', [WorkshopWageController::class, 'payWeek']);
        Route::post('/workshop-wages/weeks/reopen', [WorkshopWageController::class, 'reopenWeek'])->middleware('role:owner');
        Route::get('/workshop-wages/technicians', [WorkshopWageController::class, 'technicians']);
        Route::get('/workshop-wages/job-types', [WorkshopWageController::class, 'jobTypes']);
    });

    Route::get('/reports/{type}', [ReportController::class, 'show']);
    Route::get('/reports/{type}/pdf-link', [ReportController::class, 'pdfLink']);
    Route::get('/reports/{type}/pdf', [ReportController::class, 'pdf']);
});

Route::get('/reports/{type}/pdf-file', [ReportController::class, 'pdfFile'])
    ->name('api.reports.pdf-file')
    ->middleware('signed');
