<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceFilterRequest;
use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(AttendanceFilterRequest $request): View
    {
        $validated = $request->validated();
        $date_value = $validated['date'] ?? null;

        $date = $date_value === null
            ? Carbon::today('Asia/Tokyo')
            : Carbon::createFromFormat(
                'Y-m-d',
                $date_value,
                'Asia/Tokyo'
            )->startOfDay();

        $previous_day = $date->copy()->subDay()->toDateString();
        $next_day = $date->copy()->addDay()->toDateString();

        $users = User::where('admin_status', false)
            ->orderBy('id')
            ->get();

        $attendance_records = AttendanceRecord::with('breaks')
            ->where('date', $date->toDateString())
            ->whereIn('user_id', $users->modelKeys())
            ->get();

        return view('admin.admin-attendance-list', [
            'date' => $date,
            'previousDay' => $previous_day,
            'nextDay' => $next_day,
            'users' => $users,
            'attendanceRecords' => $attendance_records,
        ]);
    }
}