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
            ->map(fn (AttendanceCorrectRequest $application) => [
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

    private function adminIndex(): View
    {
        $applications = AttendanceCorrectRequest::with([
            'user',
            'attendanceRecord',
        ])
            ->orderByDesc('application_date')
            ->orderByDesc('id')
            ->get();

        foreach ($applications as $application) {
            $application->setRelation(
                'AttendanceRecord',
                $application->attendanceRecord
            );
        }

        return view('admin.admin-application-list', [
            'applications' => $applications,
        ]);
    }

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

            $breaks = [];

            foreach ($input['new_break_in'] ?? [] as $index => $breakIn) {
                if ($breakIn === null || $breakIn === '') {
                    continue;
                }

                $breaks[] = [
                    'break_in' => $breakIn.':00',
                    'break_out' => $input['new_break_out'][$index].':00',
                ];
            }

            $attendanceRecord->breaks()->delete();
            $attendanceRecord->breaks()->createMany($breaks);
        }, self::TRANSACTION_ATTEMPTS);

        return redirect()->route('admin_attendance_show', [
            'id' => $id,
        ]);
    }

    private function storeBreaks(
        AttendanceCorrectRequest $application,
        array $input
    ): void {
        $proposalBreaks = [];

        foreach ($input['new_break_in'] ?? [] as $index => $breakIn) {
            if ($breakIn === null || $breakIn === '') {
                continue;
            }

            $proposalBreaks[] = [
                'break_in' => $breakIn.':00',
                'break_out' => $input['new_break_out'][$index].':00',
            ];
        }

        $application->proposalBreaks()->createMany($proposalBreaks);
    }
}
