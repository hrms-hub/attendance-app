<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const STATUS_OFF_DUTY = '勤務外';
    public const STATUS_WORKING = '出勤中';
    public const STATUS_ON_BREAK = '休憩中';
    public const STATUS_FINISHED = '退勤済';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'admin_status' => 'boolean',
    ];

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function attendanceCorrectRequests(): HasMany
    {
        return $this->hasMany(AttendanceCorrectRequest::class);
    }

    public function approvedRequests(): HasMany
    {
        return $this->hasMany(
            AttendanceCorrectRequest::class,
            'approved_by'
        );
    }

    protected function attendanceStatus(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $today = CarbonImmutable::today('Asia/Tokyo')->toDateString();

                $attendance_record = $this->attendanceRecords()
                    ->where('date', $today)
                    ->first();

                if ($attendance_record === null) {
                    return self::STATUS_OFF_DUTY;
                }

                if ($attendance_record->clock_out !== null) {
                    return self::STATUS_FINISHED;
                }

                $has_open_break = $attendance_record->breaks()
                    ->whereNull('break_out')
                    ->exists();

                if ($has_open_break) {
                    return self::STATUS_ON_BREAK;
                }

                return self::STATUS_WORKING;
            },
        );
    }
}