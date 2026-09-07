<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceBranchSetting;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\User;
use App\Services\Attendance\AttendancePhotoStore;
use App\Services\NotificationDispatcher;
use App\Services\PayrollLockChecker;
use App\Support\WhatsAppPhone;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SelfAttendanceController extends Controller
{
    public function __construct(
        protected AttendancePhotoStore $photos,
        protected PayrollLockChecker $payrollLocks,
        protected NotificationDispatcher $notifier,
    ) {}

    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $this->requireActiveEmployee($user);
        $this->promoteStaleForEmployee($employee->id);

        $today = now()->toDateString();
        $row = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $today)
            ->first();

        $settings = AttendanceBranchSetting::forBranch((int) $employee->branch_id);

        return response()->json([
            'message' => 'Absensi hari ini.',
            'data' => [
                'date' => $today,
                'server_time' => now()->toIso8601String(),
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'branch_id' => $employee->branch_id,
                    'branch_name' => $employee->branch?->name,
                    'is_pic' => $employee->hasPosition(Employee::POS_PIC),
                ],
                'window' => $settings->windowPayload(),
                'attendance' => $this->serializeRow($row),
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $this->requireActiveEmployee($user);
        $this->promoteStaleForEmployee($employee->id);

        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);
        $year = (int) ($data['year'] ?? now()->year);
        $month = (int) ($data['month'] ?? now()->month);

        $rows = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereYear('attendance_date', $year)
            ->whereMonth('attendance_date', $month)
            ->orderByDesc('attendance_date')
            ->get()
            ->map(fn (EmployeeAttendance $r) => $this->serializeRow($r))
            ->values();

        return response()->json([
            'message' => 'Riwayat absensi dimuat.',
            'data' => [
                'year' => $year,
                'month' => $month,
                'rows' => $rows,
            ],
        ]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $this->requireActiveEmployee($user);
        $data = $request->validate([
            'photo' => ['required', 'string'],
        ]);

        $today = now()->toDateString();
        $now = now();
        $this->assertWithinWindow($employee, 'in', $now);
        $this->payrollLocks->assertEmployeesDateOpen([(int) $employee->id], $today);

        $existing = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $today)
            ->first();

        if ($existing?->check_in_at) {
            return response()->json(['message' => 'Anda sudah absen masuk hari ini.'], 422);
        }
        if ($existing && in_array($existing->status, [EmployeeAttendance::STATUS_LEAVE, EmployeeAttendance::STATUS_SICK], true)) {
            return response()->json(['message' => 'Hari ini sudah tercatat izin/sakit.'], 422);
        }

        $path = $this->photos->store($data['photo'], (int) $employee->id, $today, 'in');

        $row = DB::transaction(function () use ($existing, $employee, $today, $now, $path, $user) {
            $row = $existing ?: new EmployeeAttendance([
                'employee_id' => $employee->id,
                'attendance_date' => $today,
            ]);
            if ($row->check_in_photo) {
                $this->photos->delete($row->check_in_photo);
            }
            $row->fill([
                'check_in_at' => $now,
                'check_in_photo' => $path,
                'self_state' => EmployeeAttendance::SELF_CHECKED_IN,
                'status' => null,
                'input_by' => $user->id,
            ]);
            $row->save();

            return $row->fresh();
        });

        $this->notifier->notifyAttendanceCheckIn($this->attendancePayload($employee, $row, 'masuk'));

        return response()->json([
            'message' => 'Absen masuk berhasil. Jangan lupa absen pulang.',
            'data' => $this->serializeRow($row),
        ]);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $this->requireActiveEmployee($user);
        $data = $request->validate([
            'photo' => ['required', 'string'],
        ]);

        $today = now()->toDateString();
        $now = now();
        $this->assertWithinWindow($employee, 'out', $now);
        $this->payrollLocks->assertEmployeesDateOpen([(int) $employee->id], $today);

        $row = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $today)
            ->first();

        if (! $row?->check_in_at) {
            return response()->json(['message' => 'Absen masuk terlebih dahulu.'], 422);
        }
        if ($row->check_out_at) {
            return response()->json(['message' => 'Anda sudah absen pulang hari ini.'], 422);
        }

        $path = $this->photos->store($data['photo'], (int) $employee->id, $today, 'out');

        if ($row->check_out_photo) {
            $this->photos->delete($row->check_out_photo);
        }
        $row->fill([
            'check_out_at' => $now,
            'check_out_photo' => $path,
            'self_state' => EmployeeAttendance::SELF_COMPLETED,
            'status' => EmployeeAttendance::STATUS_PRESENT,
            'input_by' => $user->id,
        ]);
        $row->save();
        $row = $row->fresh();

        $this->notifier->notifyAttendanceCheckOut($this->attendancePayload($employee, $row, 'pulang'));

        return response()->json([
            'message' => 'Absen pulang berhasil. Hari ini dihitung Hadir.',
            'data' => $this->serializeRow($row),
        ]);
    }

    public function leaveOrSick(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $this->requireActiveEmployee($user);
        $data = $request->validate([
            'status' => ['required', Rule::in([EmployeeAttendance::STATUS_LEAVE, EmployeeAttendance::STATUS_SICK])],
            'note' => ['required', 'string', 'max:255'],
        ]);

        $today = now()->toDateString();
        $this->payrollLocks->assertEmployeesDateOpen([(int) $employee->id], $today);

        $existing = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $today)
            ->first();

        if ($existing?->check_in_at) {
            return response()->json(['message' => 'Sudah absen masuk; tidak bisa ganti ke izin/sakit.'], 422);
        }

        $row = $existing ?: new EmployeeAttendance([
            'employee_id' => $employee->id,
            'attendance_date' => $today,
        ]);
        $row->fill([
            'status' => $data['status'],
            'note' => $data['note'],
            'self_state' => null,
            'check_in_at' => null,
            'check_out_at' => null,
            'input_by' => $user->id,
        ]);
        $row->save();

        return response()->json([
            'message' => $data['status'] === EmployeeAttendance::STATUS_LEAVE
                ? 'Izin hari ini dicatat.'
                : 'Sakit hari ini dicatat.',
            'data' => $this->serializeRow($row->fresh()),
        ]);
    }

    public function photo(Request $request, EmployeeAttendance $attendance, string $side): Response
    {
        $user = $request->user();
        $side = $side === 'out' ? 'out' : 'in';
        $path = $side === 'out' ? $attendance->check_out_photo : $attendance->check_in_photo;

        if (! $this->canViewAttendancePhoto($user, $attendance)) {
            abort(403, 'Tidak diizinkan melihat foto absensi ini.');
        }

        $binary = $this->photos->get($path);
        if ($binary === null) {
            abort(404, 'Foto tidak ditemukan atau sudah dihapus (retensi 3 bulan).');
        }

        return response($binary, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function pendingReviews(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $this->reviewerBranchScope($user);
        if ($branchId === false) {
            return response()->json(['message' => 'Hanya Owner atau PIC cabang yang dapat meninjau.'], 403);
        }

        EmployeeAttendance::query()
            ->where('self_state', EmployeeAttendance::SELF_CHECKED_IN)
            ->whereDate('attendance_date', '<', now()->toDateString())
            ->when($branchId !== null, function ($q) use ($branchId) {
                $q->whereHas('employee', fn ($e) => $e->where('branch_id', $branchId));
            })
            ->each(fn (EmployeeAttendance $r) => $r->promoteStaleCheckInToPending());

        $rows = EmployeeAttendance::query()
            ->with(['employee:id,name,branch_id', 'employee.branch:id,name'])
            ->where('self_state', EmployeeAttendance::SELF_PENDING_REVIEW)
            ->when($branchId !== null, function ($q) use ($branchId) {
                $q->whereHas('employee', fn ($e) => $e->where('branch_id', $branchId));
            })
            ->orderByDesc('attendance_date')
            ->get()
            ->map(fn (EmployeeAttendance $r) => array_merge($this->serializeRow($r), [
                'employee_name' => $r->employee?->name,
                'branch_name' => $r->employee?->branch?->name,
                'branch_id' => $r->employee?->branch_id,
            ]))
            ->values();

        return response()->json([
            'message' => 'Daftar absensi menunggu tinjau.',
            'data' => ['rows' => $rows],
        ]);
    }

    public function review(Request $request, EmployeeAttendance $attendance): JsonResponse
    {
        $user = $request->user();
        $branchId = $this->reviewerBranchScope($user);
        if ($branchId === false) {
            return response()->json(['message' => 'Hanya Owner atau PIC cabang yang dapat meninjau.'], 403);
        }

        $attendance->load('employee');
        if ($branchId !== null && (int) $attendance->employee?->branch_id !== (int) $branchId) {
            return response()->json(['message' => 'PIC hanya meninjau cabangnya sendiri.'], 403);
        }

        if ($attendance->self_state !== EmployeeAttendance::SELF_PENDING_REVIEW
            && $attendance->self_state !== EmployeeAttendance::SELF_CHECKED_IN) {
            return response()->json(['message' => 'Absensi ini tidak menunggu tinjau.'], 422);
        }

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($attendance->self_state === EmployeeAttendance::SELF_CHECKED_IN) {
            $attendance->promoteStaleCheckInToPending();
            $attendance->refresh();
        }

        if ($data['decision'] === 'approve') {
            $attendance->fill([
                'status' => EmployeeAttendance::STATUS_PRESENT,
                'self_state' => EmployeeAttendance::SELF_APPROVED_INCOMPLETE,
                'reviewed_at' => now(),
                'reviewed_by' => $user->id,
                'review_note' => $data['note'] ?? null,
            ]);
            $message = 'Absensi disetujui sebagai Hadir (tanpa pulang).';
        } else {
            $attendance->fill([
                'status' => EmployeeAttendance::STATUS_ABSENT,
                'self_state' => EmployeeAttendance::SELF_REJECTED,
                'reviewed_at' => now(),
                'reviewed_by' => $user->id,
                'review_note' => $data['note'] ?? null,
            ]);
            $message = 'Absensi ditolak; dihitung Alpha.';
        }
        $attendance->save();

        return response()->json([
            'message' => $message,
            'data' => $this->serializeRow($attendance->fresh()),
        ]);
    }

    public function getSettings(Request $request): JsonResponse
    {
        if (! $request->user()->isOwner()) {
            return response()->json(['message' => 'Hanya Owner yang mengatur jam absensi.'], 403);
        }
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);
        $settings = AttendanceBranchSetting::forBranch((int) $data['branch_id']);

        return response()->json([
            'message' => 'Pengaturan jam absensi.',
            'data' => array_merge(
                ['branch_id' => (int) $data['branch_id'], 'exists' => (bool) $settings->exists],
                $settings->windowPayload()
            ),
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        if (! $request->user()->isOwner()) {
            return response()->json(['message' => 'Hanya Owner yang mengatur jam absensi.'], 403);
        }
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'check_in_start' => ['required', 'date_format:H:i'],
            'check_in_end' => ['required', 'date_format:H:i', 'after:check_in_start'],
            'check_out_start' => ['required', 'date_format:H:i'],
            'check_out_end' => ['required', 'date_format:H:i', 'after:check_out_start'],
        ]);

        $row = AttendanceBranchSetting::query()->updateOrCreate(
            ['branch_id' => (int) $data['branch_id']],
            [
                'check_in_start' => $data['check_in_start'].':00',
                'check_in_end' => $data['check_in_end'].':00',
                'check_out_start' => $data['check_out_start'].':00',
                'check_out_end' => $data['check_out_end'].':00',
                'updated_by' => $request->user()->id,
            ]
        );

        return response()->json([
            'message' => 'Jam absensi cabang disimpan.',
            'data' => array_merge(['branch_id' => $row->branch_id], $row->windowPayload()),
        ]);
    }

    protected function requireActiveEmployee(User $user): Employee
    {
        if (! $user->isEmployee() || ! $user->employee_id) {
            abort(403, 'Akun ini bukan akun karyawan.');
        }
        $employee = Employee::query()->with('branch:id,name')->find($user->employee_id);
        if (! $employee || ! $employee->isActive()) {
            abort(403, 'Karyawan tidak aktif. Hubungi Owner.');
        }

        return $employee;
    }

    protected function assertWithinWindow(Employee $employee, string $side, Carbon $now): void
    {
        $settings = AttendanceBranchSetting::forBranch((int) $employee->branch_id);
        $start = $side === 'out' ? $settings->check_out_start : $settings->check_in_start;
        $end = $side === 'out' ? $settings->check_out_end : $settings->check_in_end;
        $start = substr((string) $start, 0, 8);
        $end = substr((string) $end, 0, 8);
        $t = $now->format('H:i:s');
        if ($t < $start || $t > $end) {
            $label = $side === 'out' ? 'pulang' : 'masuk';
            abort(422, 'Di luar jam absen '.$label.' cabang ini ('.substr($start, 0, 5).'–'.substr($end, 0, 5).').');
        }
    }

    /** @return int|null|false null=owner all, int=branch, false=forbidden */
    protected function reviewerBranchScope(User $user): int|null|false
    {
        if ($user->isOwner()) {
            return null;
        }
        if ($user->isEmployee()) {
            $employee = Employee::query()->find($user->employee_id);
            if ($employee?->isActive() && $employee->hasPosition(Employee::POS_PIC)) {
                return (int) $employee->branch_id;
            }
        }

        return false;
    }

    protected function canViewAttendancePhoto(User $user, EmployeeAttendance $attendance): bool
    {
        if ($user->isOwner()) {
            return true;
        }
        if ($user->isEmployee() && (int) $user->employee_id === (int) $attendance->employee_id) {
            return true;
        }
        $scope = $this->reviewerBranchScope($user);
        if ($scope === false) {
            return false;
        }
        $attendance->loadMissing('employee');
        if ($scope === null) {
            return true;
        }

        return (int) $attendance->employee?->branch_id === (int) $scope;
    }

    protected function promoteStaleForEmployee(int $employeeId): void
    {
        EmployeeAttendance::query()
            ->where('employee_id', $employeeId)
            ->where('self_state', EmployeeAttendance::SELF_CHECKED_IN)
            ->whereDate('attendance_date', '<', now()->toDateString())
            ->each(fn (EmployeeAttendance $r) => $r->promoteStaleCheckInToPending());
    }

    /**
     * @return array<string, mixed>
     */
    protected function attendancePayload(Employee $employee, EmployeeAttendance $row, string $tipe): array
    {
        $phone = WhatsAppPhone::normalize($employee->phone);
        $branchName = $employee->branch?->name ?? '-';
        $name = $employee->name;
        $date = $row->attendance_date?->toDateString() ?? now()->toDateString();
        $jam = $tipe === 'pulang'
            ? ($row->check_out_at?->timezone(config('app.timezone'))->format('H:i') ?? '-')
            : ($row->check_in_at?->timezone(config('app.timezone'))->format('H:i') ?? '-');
        $label = strtoupper($tipe);
        $message = "Absen {$label} | {$name} · {$branchName} | {$date} {$jam} WIT";

        return [
            'attendance_id' => $row->id,
            'employee_id' => $employee->id,
            'name' => $name,
            'phone' => $phone,
            'branch_id' => $employee->branch_id,
            'branch_name' => $branchName,
            'attendance_date' => $date,
            'tipe' => $tipe,
            'check_in_at' => $row->check_in_at?->toIso8601String(),
            'check_out_at' => $row->check_out_at?->toIso8601String(),
            'self_state' => $row->self_state,
            'status' => $row->status,
            'message' => $message,
        ];
    }

    protected function serializeRow(?EmployeeAttendance $row): ?array
    {
        if (! $row) {
            return null;
        }

        return [
            'id' => $row->id,
            'date' => $row->attendance_date?->toDateString(),
            'status' => $row->status,
            'status_label' => $row->status ? EmployeeAttendance::label($row->status) : null,
            'note' => $row->note,
            'check_in_at' => $row->check_in_at?->toIso8601String(),
            'check_out_at' => $row->check_out_at?->toIso8601String(),
            'has_check_in_photo' => (bool) $row->check_in_photo,
            'has_check_out_photo' => (bool) $row->check_out_photo,
            'self_state' => $row->self_state,
            'reviewed_at' => $row->reviewed_at?->toIso8601String(),
            'review_note' => $row->review_note,
            'counts_as_present' => $row->countsAsPresent(),
        ];
    }
}
