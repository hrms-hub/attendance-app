<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceCorrectionRequest;
use App\Models\AttendanceCorrectRequest;
use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CorrectionRequestController extends Controller
{
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * 一般ユーザーは自分の申請、管理者は全員の申請一覧を表示する。
     *
     * @param  Request  $request  認証済みリクエスト
     * @return View 権限に対応した申請一覧画面
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        if ($user->admin_status) {
            return $this->adminIndex();
        }

        $formattedApplications = $user->attendanceCorrectRequests()
            ->orderByDesc('application_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AttendanceCorrectRequest $application): array => [
                'id' => $application->id,
                'approval_status' => $application->approval_status,
                'date' => $application->new_date->format('Y/m/d'),
                'comment' => $application->comment,
                'application_date' => $application->application_date
                    ->format('Y/m/d'),
            ])
            ->all();

        return view('user.user-application-list', [
            'user' => $user,
            'formattedApplications' => $formattedApplications,
        ]);
    }

    /**
     * 自分の修正申請に対応する勤怠詳細へ案内する。
     *
     * @param  Request  $request  認証済みリクエスト
     * @param  int  $id  修正申請ID
     * @return RedirectResponse 勤怠詳細画面への遷移
     */
    public function show(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();

        abort_if($user->admin_status, 403);

        $application = $user->attendanceCorrectRequests()
            ->findOrFail($id);

        return redirect()->route('attendance_show', [
            'id' => $application->attendance_record_id,
        ]);
    }

    /**
     * 一般ユーザーの修正申請を保存し、管理者の場合は勤怠を直接修正する。
     *
     * @param  AttendanceCorrectionRequest  $request  検証済みの修正内容
     * @param  int  $id  修正対象の勤怠ID
     * @return RedirectResponse 勤怠詳細画面への遷移
     */
    public function store(
        AttendanceCorrectionRequest $request,
        int $id
    ): RedirectResponse {
        $user = $request->user();

        if ($user->admin_status) {
            return $this->adminUpdate($request, $id);
        }

        $input = $request->validated();

        DB::transaction(function () use ($user, $input, $id): void {
            $lockedUser = User::whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $attendanceRecord = $lockedUser->attendanceRecords()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasPendingApplication = $attendanceRecord->applications()
                ->where(
                    'approval_status',
                    AttendanceCorrectRequest::STATUS_PENDING
                )
                ->exists();

            if ($hasPendingApplication) {
                return;
            }

            $application = $attendanceRecord->applications()->create([
                'user_id' => $lockedUser->id,
                'new_date' => $attendanceRecord->date->toDateString(),
                'new_clock_in' => $input['new_clock_in'].':00',
                'new_clock_out' => $input['new_clock_out'].':00',
                'comment' => $input['comment'],
                'approval_status' => AttendanceCorrectRequest::STATUS_PENDING,
                'application_date' => CarbonImmutable::today('Asia/Tokyo')
                    ->toDateString(),
            ]);

            $this->storeBreaks($application, $input);
        });

        return redirect()->route('attendance_show', ['id' => $id]);
    }

    /**
     * 申請者と勤怠を一括取得し、管理者用の申請一覧を表示する。
     *
     * @return View 管理者用の申請一覧画面
     */
    private function adminIndex(): View
    {
        $applications = AttendanceCorrectRequest::with([
            'user',
            'attendanceRecord',
        ])
            ->orderByDesc('application_date')
            ->orderByDesc('id')
            ->get();

        $applications->each(function (AttendanceCorrectRequest $application): void {
            $application->setRelation(
                'AttendanceRecord',
                $application->attendanceRecord
            );
        });

        return view('admin.admin-application-list', [
            'applications' => $applications,
        ]);
    }

    /**
     * 管理者が勤怠と休憩を排他制御のもとで修正する。
     *
     * @param  AttendanceCorrectionRequest  $request  検証済みの修正内容
     * @param  int  $id  修正対象の勤怠ID
     * @return RedirectResponse 管理者用の勤怠詳細画面への遷移
     */
    private function adminUpdate(
        AttendanceCorrectionRequest $request,
        int $id
    ): RedirectResponse {
        abort_unless($request->user()->admin_status, 403);

        $input = $request->validated();

        $ownerId = AttendanceRecord::findOrFail($id)->user_id;

        DB::transaction(function () use ($ownerId, $input, $id): void {
            $lockedUser = User::whereKey($ownerId)
                ->lockForUpdate()
                ->firstOrFail();

            $attendanceRecord = $lockedUser->attendanceRecords()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            $attendanceRecord->update([
                'clock_in' => $input['new_clock_in'].':00',
                'clock_out' => $input['new_clock_out'].':00',
                'comment' => $input['comment'],
            ]);

            $breaks = $this->formatBreaks($input);

            $attendanceRecord->breaks()->delete();
            $attendanceRecord->breaks()->createMany($breaks);
        }, self::TRANSACTION_ATTEMPTS);

        return redirect()->route('admin_attendance_show', [
            'id' => $id,
        ]);
    }

    /**
     * 修正申請に紐づく休憩の提案内容を保存する。
     *
     * @param  AttendanceCorrectRequest  $application  保存先の修正申請
     * @param  array<string, mixed>  $input  検証済みの入力値
     */
    private function storeBreaks(
        AttendanceCorrectRequest $application,
        array $input
    ): void {
        $application->proposalBreaks()->createMany($this->formatBreaks($input));
    }

    /**
     * 空欄を除いた休憩入力を保存用の秒付き時刻に変換する。
     *
     * @param  array<string, mixed>  $input  検証済みの入力値
     * @return array<int, array{break_in: string, break_out: string}> 保存する休憩データ
     */
    private function formatBreaks(array $input): array
    {
        return collect($input['new_break_in'] ?? [])
            ->filter(fn (?string $breakIn): bool => $breakIn !== null && $breakIn !== '')
            ->map(fn (string $breakIn, int|string $index): array => [
                'break_in' => $breakIn.':00',
                'break_out' => $input['new_break_out'][$index].':00',
            ])
            ->values()
            ->all();
    }
}
