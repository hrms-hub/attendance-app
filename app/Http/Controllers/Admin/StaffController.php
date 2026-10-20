<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceFilterRequest;
use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffController extends Controller
{
    /**
     * 勤怠確認の対象となる一般ユーザー一覧を表示する。
     *
     * @param  Request  $request  認証済みの管理者リクエスト
     * @return View スタッフ一覧画面
     */
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

    /**
     * 指定スタッフの月次勤怠一覧を表示する。
     *
     * @param  AttendanceFilterRequest  $request  対象月の検索条件
     * @param  int  $id  対象スタッフのユーザーID
     * @return View スタッフ別の月次勤怠一覧画面
     */
    public function show(AttendanceFilterRequest $request, int $id): View
    {
        abort_unless($request->user()->admin_status, 403);

        $user = User::where('admin_status', false)
            ->findOrFail($id);

        $dateValue = $request->validated('date');

        $date = $dateValue === null
            ? CarbonImmutable::today('Asia/Tokyo')->startOfMonth()
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
                fn (AttendanceRecord $attendanceRecord): string => $attendanceRecord->date->toDateString()
            );

        $formattedAttendanceRecords = collect(range(1, $date->daysInMonth))
            ->map(function (int $day) use ($date, $attendanceRecords): array {
                $currentDate = $date->day($day);
                $attendanceRecord = $attendanceRecords->get(
                    $currentDate->toDateString()
                );

                return [
                    'id' => $attendanceRecord?->id,
                    'date' => $currentDate->isoFormat('MM/DD(ddd)'),
                    'clock_in' => substr($attendanceRecord?->clock_in ?? '', 0, 5),
                    'clock_out' => substr($attendanceRecord?->clock_out ?? '', 0, 5),
                    'total_break_time' => $attendanceRecord?->total_break_time,
                    'total_time' => $attendanceRecord?->total_time,
                ];
            })
            ->all();

        return view('admin.staff-attendance-list', [
            'user' => $user,
            'date' => $date,
            'previousMonth' => $date->subMonth()->format('Y-m'),
            'nextMonth' => $date->addMonth()->format('Y-m'),
            'formattedAttendanceRecords' => $formattedAttendanceRecords,
        ]);
    }
}
