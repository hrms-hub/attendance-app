<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceCorrectionRequest;
use App\Models\AttendanceCorrectRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use App\Models\AttendanceRecord;

class CorrectionRequestController extends Controller
{


    public function index(Request $request): View
    {
        $user = $request->user();

        if ($user->admin_status) {
            return $this->adminIndex();
        }

        $formatted_applications = $user->attendanceCorrectRequests()
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
            'formattedApplications' => $formatted_applications,
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
            $locked_user = User::whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $attendance_record = $locked_user->attendanceRecords()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            $has_pending_application = $attendance_record->applications()
                ->where(
                    'approval_status',
                    AttendanceCorrectRequest::STATUS_PENDING
                )
                ->exists();

            if ($has_pending_application) {
                return;
            }

            $application = $attendance_record->applications()->create([
                'user_id' => $locked_user->id,
                'new_date' => $attendance_record->date->toDateString(),
                'new_clock_in' => $input['new_clock_in'] . ':00',
                'new_clock_out' => $input['new_clock_out'] . ':00',
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

        $owner_id = AttendanceRecord::findOrFail($id)->user_id;

        DB::transaction(function () use ($owner_id, $input, $id): void {
            $locked_user = User::whereKey($owner_id)
                ->lockForUpdate()
                ->firstOrFail();

            $attendance_record = $locked_user->attendanceRecords()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            $attendance_record->update([
                'clock_in' => $input['new_clock_in'] . ':00',
                'clock_out' => $input['new_clock_out'] . ':00',
                'comment' => $input['comment'],
            ]);

            $breaks = [];

            foreach ($input['new_break_in'] ?? [] as $index => $break_in) {
                if ($break_in === null || $break_in === '') {
                    continue;
                }

                $breaks[] = [
                    'break_in' => $break_in . ':00',
                    'break_out' => $input['new_break_out'][$index] . ':00',
                ];
            }

            $attendance_record->breaks()->delete();
            $attendance_record->breaks()->createMany($breaks);
        }, 3);

        return redirect()->route('admin_attendance_show', [
            'id' => $id,
        ]);
    }


    private function storeBreaks(
        AttendanceCorrectRequest $application,
        array $input
    ): void {
        $proposal_breaks = [];

        foreach ($input['new_break_in'] ?? [] as $index => $break_in) {
            if ($break_in === null || $break_in === '') {
                continue;
            }

            $proposal_breaks[] = [
                'break_in' => $break_in . ':00',
                'break_out' => $input['new_break_out'][$index] . ':00',
            ];
        }

        $application->proposalBreaks()->createMany($proposal_breaks);
    }
}