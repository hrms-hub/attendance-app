<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrectRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CorrectionRequestController extends Controller
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function show(Request $request, int $id): View
    {
        abort_unless($request->user()->admin_status, 403);

        $application = AttendanceCorrectRequest::with([
            'user',
            'proposalBreaks' => fn ($query) => $query->orderBy('id'),
        ])->findOrFail($id);

        $application->new_clock_in = substr(
            $application->new_clock_in,
            0,
            5
        );

        $application->new_clock_out = substr(
            $application->new_clock_out,
            0,
            5
        );

        return view('admin.admin-application-detail', [
            'user' => $application->user,
            'application' => $application,
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $admin = $request->user();

        abort_unless($admin->admin_status, 403);

        $applicationReference = AttendanceCorrectRequest::findOrFail($id);

        DB::transaction(function () use (
            $admin,
            $applicationReference,
            $id
        ): void {
            $lockedUser = User::whereKey($applicationReference->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $attendanceRecord = $lockedUser->attendanceRecords()
                ->whereKey($applicationReference->attendance_record_id)
                ->lockForUpdate()
                ->firstOrFail();

            $application = $attendanceRecord->applications()
                ->whereKey($id)
                ->where('user_id', $lockedUser->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $application->approval_status
                !== AttendanceCorrectRequest::STATUS_PENDING
            ) {
                return;
            }

            $proposalBreaks = $application->proposalBreaks()
                ->orderBy('id')
                ->get()
                ->map(fn ($proposalBreak) => [
                    'break_in' => $proposalBreak->break_in,
                    'break_out' => $proposalBreak->break_out,
                ])
                ->all();

            $attendanceRecord->update([
                'clock_in' => $application->new_clock_in,
                'clock_out' => $application->new_clock_out,
                'comment' => $application->comment,
            ]);

            $attendanceRecord->breaks()->delete();
            $attendanceRecord->breaks()->createMany($proposalBreaks);

            $application->update([
                'approval_status' => AttendanceCorrectRequest::STATUS_APPROVED,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);
        }, self::TRANSACTION_ATTEMPTS);

        return redirect()->route('admin_application_show', [
            'id' => $id,
        ]);
    }
}
