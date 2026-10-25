<?php

use App\Http\Controllers\Api\V1\AttendanceRecordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/attendance-records', [AttendanceRecordController::class, 'index'])
        ->name('attendance-records.index');
    Route::get('/attendance-records/{attendanceRecord}', [AttendanceRecordController::class, 'show'])
        ->name('attendance-records.show');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/attendance-records', [AttendanceRecordController::class, 'store'])
            ->name('attendance-records.store');
        Route::match(['put', 'patch'], '/attendance-records/{attendanceRecord}', [AttendanceRecordController::class, 'update'])
            ->name('attendance-records.update');
        Route::delete('/attendance-records/{attendanceRecord}', [AttendanceRecordController::class, 'destroy'])
            ->name('attendance-records.destroy');
    });
});
