<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
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

    /**
     * ユーザー自身の勤怠を取得する。
     *
     * @return HasMany 勤怠のリレーション
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * ユーザーが提出した勤怠修正申請を取得する。
     *
     * @return HasMany 提出した修正申請のリレーション
     */
    public function attendanceCorrectRequests(): HasMany
    {
        return $this->hasMany(AttendanceCorrectRequest::class);
    }

    /**
     * ユーザーが承認した勤怠修正申請を取得する。
     *
     * @return HasMany 承認した修正申請のリレーション
     */
    public function approvedRequests(): HasMany
    {
        return $this->hasMany(
            AttendanceCorrectRequest::class,
            'approved_by'
        );
    }

    /**
     * 当日の打刻と未終了の休憩から勤務状態を判定する。
     *
     * @return Attribute 勤務状態を返すアクセサ
     */
    protected function attendanceStatus(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $today = CarbonImmutable::today('Asia/Tokyo')->toDateString();

                $attendanceRecord = $this->attendanceRecords()
                    ->where('date', $today)
                    ->first();

                if ($attendanceRecord === null) {
                    return self::STATUS_OFF_DUTY;
                }

                if ($attendanceRecord->clock_out !== null) {
                    return self::STATUS_FINISHED;
                }

                $hasOpenBreak = $attendanceRecord->breaks()
                    ->whereNull('break_out')
                    ->exists();

                if ($hasOpenBreak) {
                    return self::STATUS_ON_BREAK;
                }

                return self::STATUS_WORKING;
            },
        );
    }
}
