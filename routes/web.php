<?php

use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AttendanceController;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\Admin\AttendanceController as AdminAttendanceController;
use App\Http\Middleware\AdminMiddleware;

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

Route::middleware('auth')->group(function () {
    Route::get('/attendance', [AttendanceController::class, 'create'])
        ->name('attendance_create');

    Route::post('/attendance', [AttendanceController::class, 'store'])
        ->name('attendance_store');
});

Route::middleware(['auth', AdminMiddleware::class])->group(function () {
    Route::get('/admin/attendance/list', [AdminAttendanceController::class, 'index'])
        ->name('admin_attendance_list');

    Route::post('/admin/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('admin_logout');
});