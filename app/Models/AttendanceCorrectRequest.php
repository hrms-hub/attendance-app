<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceCorrectRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = '承認待ち';

    public const STATUS_APPROVED = '承認済み';

    protected $fillable = [
        'attendance_record_id',
        'user_id',
        'new_date',
        'new_clock_in',
        'new_clock_out',
        'comment',
        'approval_status',
        'application_date',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'new_date' => 'date',
        'application_date' => 'date',
        'approved_at' => 'datetime',
    ];

    /**
     * 修正申請の対象となる勤怠を取得する。
     *
     * @return BelongsTo 修正対象の勤怠のリレーション
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    /**
     * 修正申請を提出したユーザーを取得する。
     *
     * @return BelongsTo 申請者のリレーション
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 修正申請を承認したユーザーを取得する。
     *
     * @return BelongsTo 承認者のリレーション。未承認時の関連値はnull
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * 修正申請に含まれる休憩の提案内容を取得する。
     *
     * @return HasMany 提案された休憩のリレーション
     */
    public function proposalBreaks(): HasMany
    {
        return $this->hasMany(ProposalBreak::class);
    }
}
