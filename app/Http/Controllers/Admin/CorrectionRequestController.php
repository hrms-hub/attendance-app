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

        $application_reference = AttendanceCorrectRequest::findOrFail($id);

        DB::transaction(function () use (
            $admin,
            $application_reference,
            $id
        ): void {
            $locked_user = User::whereKey($application_reference->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $attendance_record = $locked_user->attendanceRecords()
                ->whereKey($application_reference->attendance_record_id)
                ->lockForUpdate()
                ->firstOrFail();

            $application = $attendance_record->applications()
                ->whereKey($id)
                ->where('user_id', $locked_user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $application->approval_status
                !== AttendanceCorrectRequest::STATUS_PENDING
            ) {
                return;
            }

            $proposal_breaks = $application->proposalBreaks()
                ->orderBy('id')
                ->get()
                ->map(fn ($proposal_break) => [
                    'break_in' => $proposal_break->break_in,
                    'break_out' => $proposal_break->break_out,
                ])
                ->all();

            $attendance_record->update([
                'clock_in' => $application->new_clock_in,
                'clock_out' => $application->new_clock_out,
                'comment' => $application->comment,
            ]);

            $attendance_record->breaks()->delete();
            $attendance_record->breaks()->createMany($proposal_breaks);

            $application->update([
                'approval_status' => AttendanceCorrectRequest::STATUS_APPROVED,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);
        }, 3);

        return redirect()->route('admin_application_show', [
            'id' => $id,
        ]);
    }
}