<?php

namespace App\Http\Controllers;

use App\Http\Requests\PunchRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Http\Requests\AttendanceFilterRequest;
use App\Models\AttendanceRecord;
use Illuminate\Support\Collection;
use App\Models\AttendanceCorrectRequest;
use App\Services\AttendanceDetailData;

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

        $date_value = $request->validated('date');

        $date = $date_value === null
            ? CarbonImmutable::now('Asia/Tokyo')->startOfMonth()
            : CarbonImmutable::parse($date_value . '-01', 'Asia/Tokyo');

        $date = $date->locale('ja');

        $attendance_records = $user->attendanceRecords()
            ->with('breaks')
            ->whereBetween('date', [
                $date->toDateString(),
                $date->endOfMonth()->toDateString(),
            ])
            ->get()
            ->keyBy(
                fn (AttendanceRecord $attendance_record) =>
                    $attendance_record->date->toDateString()
            );

        return view('user.user-attendance-list', [
            'date' => $date,
            'previousMonth' => $date->subMonth()->format('Y-m'),
            'nextMonth' => $date->addMonth()->format('Y-m'),
            'formattedAttendanceRecords' => $this->formatAttendanceRecords(
                $date,
                $attendance_records
            ),
        ]);
    }



public function show(
    Request $request,
    int $id,
    AttendanceDetailData $detail_data
): View {
    $user = $request->user();

    abort_if($user->admin_status, 403);

    $attendance_record = $user->attendanceRecords()
        ->with('breaks')
        ->findOrFail($id);

    $application = $attendance_record->applications()
        ->with('proposalBreaks')
        ->where(
            'approval_status',
            AttendanceCorrectRequest::STATUS_PENDING
        )
        ->first();

    return view('user.user-detail', [
        'user' => $user,
        'data' => $detail_data->make(
            $attendance_record,
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

            $attendance_record = $user->attendanceRecords()
                ->where('date', $date)
                ->lockForUpdate()
                ->first();

            if ($action === PunchRequest::ACTION_CLOCK_IN) {
                if ($attendance_record === null) {
                    $user->attendanceRecords()->create([
                        'date' => $date,
                        'clock_in' => $time,
                    ]);
                }

                return;
            }

            if ($attendance_record === null || $attendance_record->clock_out !== null) {
                return;
            }

            $open_break = $attendance_record->breaks()
                ->whereNull('break_out')
                ->lockForUpdate()
                ->first();

            if ($action === PunchRequest::ACTION_CLOCK_OUT) {
                if ($open_break === null) {
                    $attendance_record->update([
                        'clock_out' => $time,
                    ]);
                }

                return;
            }

            if ($action === PunchRequest::ACTION_BREAK_IN) {
                if ($open_break === null) {
                    $attendance_record->breaks()->create([
                        'break_in' => $time,
                    ]);
                }

                return;
            }

            if ($action === PunchRequest::ACTION_BREAK_OUT && $open_break !== null) {
                $open_break->update([
                    'break_out' => $time,
                ]);
            }
        });

               return redirect('/attendance');
    }

    private function formatAttendanceRecords(
        CarbonImmutable $date,
        Collection $attendance_records
    ): array {
        $formatted_records = [];

        for ($day = 1; $day <= $date->daysInMonth; $day++) {
            $current_date = $date->day($day);
            $attendance_record = $attendance_records->get(
                $current_date->toDateString()
            );

            $formatted_records[] = [
                'id' => $attendance_record?->id,
                'date' => $current_date->isoFormat('MM/DD(ddd)'),
                'clock_in' => $this->formatClockTime($attendance_record?->clock_in),
                'clock_out' => $this->formatClockTime($attendance_record?->clock_out),
                'total_break_time' => $attendance_record?->total_break_time,
                'total_time' => $attendance_record?->total_time,
            ];
        }

        return $formatted_records;
    }

    private function formatClockTime(?string $time): string
    {
        return $time === null ? '' : substr($time, 0, 5);
    }
}