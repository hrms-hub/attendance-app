<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceFilterRequest;
use App\Http\Requests\PunchRequest;
use App\Models\AttendanceCorrectRequest;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\AttendanceDetailData;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function create(Request $request): View
    {
        $user = $request->user();

        abort_if($user->admin_status, 403);

        $now = CarbonImmutable::now('Asia/Tokyo')->locale('ja');

        return view('user.attendance-register', [
            'user' => $user,
            'formattedDate' => $now->isoFormat('YYYY年M月D日(ddd)'),
            'formattedTime' => $now->format('H:i'),
        ]);
    }

    public function index(AttendanceFilterRequest $request): View
    {
        $user = $request->user();

        abort_if($user->admin_status, 403);

        $dateValue = $request->validated('date');

        $date = $dateValue === null
            ? CarbonImmutable::now('Asia/Tokyo')->startOfMonth()
            : CarbonImmutable::parse($dateValue.'-01', 'Asia/Tokyo');

        $date = $date->locale('ja');

        $attendanceRecords = $user->attendanceRecords()
            ->with('breaks')
            ->whereBetween('date', [
                $date->toDateString(),
                $date->endOfMonth()->toDateString(),
            ])
            ->get()
            ->keyBy(
                fn (AttendanceRecord $attendanceRecord) => $attendanceRecord->date->toDateString()
            );

        return view('user.user-attendance-list', [
            'date' => $date,
            'previousMonth' => $date->subMonth()->format('Y-m'),
            'nextMonth' => $date->addMonth()->format('Y-m'),
            'formattedAttendanceRecords' => $this->formatAttendanceRecords(
                $date,
                $attendanceRecords
            ),
        ]);
    }

    public function show(
        Request $request,
        int $id,
        AttendanceDetailData $detailData
    ): View|RedirectResponse {
        $user = $request->user();

        if ($user->admin_status) {
            return redirect()->route('admin_attendance_show', [
                'id' => $id,
            ]);
        }

        $attendanceRecord = $user->attendanceRecords()
            ->with('breaks')
            ->findOrFail($id);

        $application = $attendanceRecord->applications()
            ->with('proposalBreaks')
            ->where(
                'approval_status',
                AttendanceCorrectRequest::STATUS_PENDING
            )
            ->first();

        return view('user.user-detail', [
            'user' => $user,
            'data' => $detailData->make(
                $attendanceRecord,
                $application,
                $request->old()
            ),
        ]);
    }

    public function store(PunchRequest $request): RedirectResponse
    {
        $action = $request->validated('action');

        DB::transaction(function () use ($request, $action): void {
            $user = User::query()
                ->whereKey($request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();

            $now = CarbonImmutable::now('Asia/Tokyo');
            $date = $now->toDateString();
            $time = $now->format('H:i:s');

            $attendanceRecord = $user->attendanceRecords()
                ->where('date', $date)
                ->lockForUpdate()
                ->first();

            if ($action === PunchRequest::ACTION_CLOCK_IN) {
                if ($attendanceRecord === null) {
                    $user->attendanceRecords()->create([
                        'date' => $date,
                        'clock_in' => $time,
                    ]);
                }

                return;
            }

            if ($attendanceRecord === null || $attendanceRecord->clock_out !== null) {
                return;
            }

            $openBreak = $attendanceRecord->breaks()
                ->whereNull('break_out')
                ->lockForUpdate()
                ->first();

            if ($action === PunchRequest::ACTION_CLOCK_OUT) {
                if ($openBreak === null) {
                    $attendanceRecord->update([
                        'clock_out' => $time,
                    ]);
                }

                return;
            }

            if ($action === PunchRequest::ACTION_BREAK_IN) {
                if ($openBreak === null) {
                    $attendanceRecord->breaks()->create([
                        'break_in' => $time,
                    ]);
                }

                return;
            }

            if ($action === PunchRequest::ACTION_BREAK_OUT && $openBreak !== null) {
                $openBreak->update([
                    'break_out' => $time,
                ]);
            }
        });

        return redirect('/attendance');
    }

    private function formatAttendanceRecords(
        CarbonImmutable $date,
        Collection $attendanceRecords
    ): array {
        $formattedRecords = [];

        for ($day = 1; $day <= $date->daysInMonth; $day++) {
            $currentDate = $date->day($day);
            $attendanceRecord = $attendanceRecords->get(
                $currentDate->toDateString()
            );

            $formattedRecords[] = [
                'id' => $attendanceRecord?->id,
                'date' => $currentDate->isoFormat('MM/DD(ddd)'),
                'clock_in' => $this->formatClockTime($attendanceRecord?->clock_in),
                'clock_out' => $this->formatClockTime($attendanceRecord?->clock_out),
                'total_break_time' => $attendanceRecord?->total_break_time,
                'total_time' => $attendanceRecord?->total_time,
            ];
        }

        return $formattedRecords;
    }

    private function formatClockTime(?string $time): string
    {
        return $time === null ? '' : substr($time, 0, 5);
    }
}
