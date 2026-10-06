<?php

namespace App\Http\Controllers;

use App\Http\Requests\PunchRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}