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
    /**
     * 一般ユーザーの勤怠打刻画面を表示する。
     *
     * @param  Request  $request  認証済みリクエスト
     * @return View 勤怠打刻画面
     */
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

    /**
     * ログインユーザーの指定月の勤怠一覧を表示する。
     *
     * @param  AttendanceFilterRequest  $request  対象月の検索条件
     * @return View 月次勤怠一覧画面
     */
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
                fn (AttendanceRecord $attendanceRecord): string => $attendanceRecord->date->toDateString()
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

    /**
     * 自分の勤怠詳細を表示し、管理者は管理画面へ案内する。
     *
     * @param  Request  $request  認証済みリクエスト
     * @param  int  $id  勤怠ID
     * @param  AttendanceDetailData  $detailData  詳細画面の表示データ作成処理
     * @return View|RedirectResponse 勤怠詳細画面または管理画面への遷移
     */
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

    /**
     * 出勤・退勤・休憩の打刻を排他制御のもとで記録する。
     *
     * @param  PunchRequest  $request  検証済みの打刻操作
     * @return RedirectResponse 勤怠打刻画面への遷移
     */
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

    /**
     * 月内の全日付に対して勤怠一覧の表示データを作成する。
     *
     * @param  CarbonImmutable  $date  対象月の基準日
     * @param  Collection<string, AttendanceRecord>  $attendanceRecords  日付をキーにした勤怠
     * @return array<int, array<string, int|string|null>> 日付順の表示データ
     */
    private function formatAttendanceRecords(
        CarbonImmutable $date,
        Collection $attendanceRecords
    ): array {
        return collect(range(1, $date->daysInMonth))
            ->map(function (int $day) use ($date, $attendanceRecords): array {
                $currentDate = $date->day($day);
                $attendanceRecord = $attendanceRecords->get(
                    $currentDate->toDateString()
                );

                return [
                    'id' => $attendanceRecord?->id,
                    'date' => $currentDate->isoFormat('MM/DD(ddd)'),
                    'clock_in' => $this->formatClockTime($attendanceRecord?->clock_in),
                    'clock_out' => $this->formatClockTime($attendanceRecord?->clock_out),
                    'total_break_time' => $attendanceRecord?->total_break_time,
                    'total_time' => $attendanceRecord?->total_time,
                ];
            })
            ->all();
    }

    /**
     * 打刻時刻を時間と分に整形する。
     *
     * @param  string|null  $time  HH:MM:SS形式の時刻
     * @return string HH:MM形式の時刻。未打刻の場合は空文字
     */
    private function formatClockTime(?string $time): string
    {
        return $time === null ? '' : substr($time, 0, 5);
    }
}
