<?php

use App\Http\Controllers\Admin\AttendanceController as AdminAttendanceController;
use App\Http\Controllers\Admin\AttendanceExportController;
use App\Http\Controllers\Admin\CorrectionRequestController as AdminCorrectionRequestController;
use App\Http\Controllers\Admin\StaffController as AdminStaffController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CorrectionRequestController;
use App\Http\Middleware\AdminMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::get('/', function (Request $request) {
    $user = $request->user();

    if ($user === null) {
        return redirect('/login');
    }

    return redirect(
        $user->admin_status
            ? '/admin/attendance/list'
            : '/attendance'
    );
});

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->name('register_store');

    Route::view('/admin/login', 'admin.admin-login')
        ->name('admin_login');

    Route::post('/admin/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('admin_login_store');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/attendance', [AttendanceController::class, 'create'])
        ->name('attendance_create');

    Route::post('/attendance', [AttendanceController::class, 'store'])
        ->name('attendance_store');

    Route::get('/attendance/list', [AttendanceController::class, 'index'])
        ->name('attendance_index');

    Route::get('/attendance/detail/{id}', [AttendanceController::class, 'show'])
        ->whereNumber('id')
        ->name('attendance_show');

    Route::get('/attendance/report', [AttendanceReportController::class, 'index'])
        ->name('attendance_report');

    Route::get('/attendance/{id}', [AttendanceController::class, 'show'])
        ->whereNumber('id')
        ->name('attendance_show_legacy');

    Route::post('/attendance/detail/{id}', [CorrectionRequestController::class, 'store'])
        ->whereNumber('id')
        ->name('attendance_correction_store');

    Route::post('/attendance/{id}', [CorrectionRequestController::class, 'store'])
        ->whereNumber('id')
        ->name('attendance_correction_store_legacy');

    Route::get('/stamp_correction_request/list', [CorrectionRequestController::class, 'index'])
        ->name('application_index');

    Route::get('/application/list', [CorrectionRequestController::class, 'index'])
        ->name('application_index_legacy');

    Route::get('/application/{id}', [CorrectionRequestController::class, 'show'])
        ->whereNumber('id')
        ->name('application_show');

});

Route::middleware(['auth', AdminMiddleware::class])->group(function () {
    Route::get('/admin/attendance/list', [AdminAttendanceController::class, 'index'])
        ->name('admin_attendance_list');

    Route::get('/admin/attendance/{id}', [AdminAttendanceController::class, 'show'])
        ->whereNumber('id')
        ->name('admin_attendance_show');

    Route::get('/admin/staff/list', [AdminStaffController::class, 'index'])
        ->name('admin_staff_list');

    Route::get('/admin/attendance/staff/{id}', [AdminStaffController::class, 'show'])
        ->whereNumber('id')
        ->name('admin_staff_attendance_list');

    Route::post('/export', AttendanceExportController::class)
        ->name('admin_attendance_export');

    Route::post('/admin/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('admin_logout');

    Route::get(
        '/stamp_correction_request/approve/{id}',
        [AdminCorrectionRequestController::class, 'show']
    )
        ->whereNumber('id')
        ->name('admin_application_show');

    Route::post(
        '/stamp_correction_request/approve/{id}',
        [AdminCorrectionRequestController::class, 'approve']
    )
        ->whereNumber('id')
        ->name('admin_application_approve');
});
