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

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function proposalBreaks(): HasMany
    {
        return $this->hasMany(ProposalBreak::class);
    }
}