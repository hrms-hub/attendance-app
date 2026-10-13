<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Http\Requests\AttendanceFilterRequest;
use App\Models\AttendanceRecord;
use Carbon\CarbonImmutable;

class StaffController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->admin_status, 403);

        $users = User::query()
            ->where('admin_status', false)
            ->orderBy('id')
            ->get(['id', 'name', 'email']);

        return view('admin.staff-list', [
            'users' => $users,
        ]);
    }

    public function show(AttendanceFilterRequest $request, int $id): View
    {
        abort_unless($request->user()->admin_status, 403);

        $user = User::where('admin_status', false)
            ->findOrFail($id);

        $date_value = $request->validated('date');

        $date = $date_value === null
            ? CarbonImmutable::today('Asia/Tokyo')->startOfMonth()
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

        $formatted_attendance_records = [];

        for ($day = 1; $day <= $date->daysInMonth; $day++) {
            $current_date = $date->day($day);
            $attendance_record = $attendance_records->get(
                $current_date->toDateString()
            );

            $formatted_attendance_records[] = [
                'id' => $attendance_record?->id,
                'date' => $current_date->isoFormat('MM/DD(ddd)'),
                'clock_in' => substr($attendance_record?->clock_in ?? '', 0, 5),
                'clock_out' => substr($attendance_record?->clock_out ?? '', 0, 5),
                'total_break_time' => $attendance_record?->total_break_time,
                'total_time' => $attendance_record?->total_time,
            ];
        }

        return view('admin.staff-attendance-list', [
            'user' => $user,
            'date' => $date,
            'previousMonth' => $date->subMonth()->format('Y-m'),
            'nextMonth' => $date->addMonth()->format('Y-m'),
            'formattedAttendanceRecords' => $formatted_attendance_records,
        ]);
    }



}