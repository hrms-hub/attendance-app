<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalBreak extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_correct_request_id',
        'break_in',
        'break_out',
    ];

    /**
     * 休憩の提案内容が紐づく修正申請を取得する。
     *
     * @return BelongsTo 対象の修正申請のリレーション
     */
    public function attendanceCorrectRequest(): BelongsTo
    {
        return $this->belongsTo(AttendanceCorrectRequest::class);
    }
}
