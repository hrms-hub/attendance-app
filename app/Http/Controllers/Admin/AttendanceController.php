<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceFilterRequest;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\AttendanceDetailData;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(AttendanceFilterRequest $request): View
    {
        $validated = $request->validated();
        $dateValue = $validated['date'] ?? null;

        $date = $dateValue === null
            ? Carbon::today('Asia/Tokyo')
            : Carbon::createFromFormat(
                'Y-m-d',
                $dateValue,
                'Asia/Tokyo'
            )->startOfDay();

        $previousDay = $date->copy()->subDay()->toDateString();
        $nextDay = $date->copy()->addDay()->toDateString();

        $users = User::where('admin_status', false)
            ->orderBy('id')
            ->get();

        $attendanceRecords = AttendanceRecord::with('breaks')
            ->where('date', $date->toDateString())
            ->whereIn('user_id', $users->modelKeys())
            ->get();

        return view('admin.admin-attendance-list', [
            'date' => $date,
            'previousDay' => $previousDay,
            'nextDay' => $nextDay,
            'users' => $users,
            'attendanceRecords' => $attendanceRecords,
        ]);
    }

    public function show(
        Request $request,
        int $id,
        AttendanceDetailData $detailData
    ): View {
        abort_unless($request->user()->admin_status, 403);

        $attendanceRecord = AttendanceRecord::with(['user', 'breaks'])
            ->findOrFail($id);

        return view('admin.admin-detail', [
            'user' => $attendanceRecord->user,
            'attendanceRecord' => $detailData->make(
                $attendanceRecord,
                null,
                $request->old()
            ),
        ]);
    }
}
