<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceRecord extends Model
{
    use HasFactory;

    private const SECONDS_PER_HOUR = 3600;

    private const SECONDS_PER_MINUTE = 60;

    protected $fillable = [
        'user_id',
        'date',
        'clock_in',
        'clock_out',
        'comment',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * 勤怠を登録したユーザーを取得する。
     *
     * @return BelongsTo 対象ユーザーのリレーション
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 勤怠に紐づく休憩を取得する。
     *
     * @return HasMany 休憩のリレーション
     */
    public function breaks(): HasMany
    {
        return $this->hasMany(BreakRecord::class);
    }

    /**
     * 勤怠に対する修正申請を取得する。
     *
     * @return HasMany 修正申請のリレーション
     */
    public function applications(): HasMany
    {
        return $this->hasMany(AttendanceCorrectRequest::class);
    }

    /**
     * 終了済み休憩の合計時間を計算するアクセサを定義する。
     *
     * @return Attribute 合計休憩時間。終了済み休憩がなければ値はnull
     */
    protected function totalBreakTime(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if ($this->breaks->whereNotNull('break_out')->isEmpty()) {
                    return null;
                }

                return $this->formatDuration($this->totalBreakSeconds());
            }
        );
    }

    /**
     * 出退勤の経過時間から終了済み休憩を引いた実働時間を計算する。
     *
     * @return Attribute 実働時間。退勤前は値がnullになるアクセサ
     */
    protected function totalTime(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if ($this->clock_out === null) {
                    return null;
                }

                $workingSeconds = $this->timeToTimestamp($this->clock_out)
                    - $this->timeToTimestamp($this->clock_in)
                    - $this->totalBreakSeconds();

                return $this->formatDuration($workingSeconds);
            }
        );
    }

    /**
     * 終了済み休憩の経過秒数を合計する。
     *
     * @return int 合計休憩秒数
     */
    private function totalBreakSeconds(): int
    {
        return (int) $this->breaks
            ->whereNotNull('break_out')
            ->sum(fn (BreakRecord $breakRecord): int => $this->timeToTimestamp($breakRecord->break_out)
                - $this->timeToTimestamp($breakRecord->break_in)
            );
    }

    /**
     * 勤怠日と時刻を日本時間のタイムスタンプに変換する。
     *
     * @param  string  $time  HH:MM:SS形式の時刻
     * @return int UNIXタイムスタンプ
     */
    private function timeToTimestamp(string $time): int
    {
        $date = $this->date->format('Y-m-d');

        return Carbon::parse($date.' '.$time, 'Asia/Tokyo')
            ->getTimestamp();
    }

    /**
     * 経過秒数を時間・分・秒の表示形式に変換する。
     *
     * @param  int  $seconds  経過秒数
     * @return string HH:MM:SS形式の経過時間
     */
    private function formatDuration(int $seconds): string
    {
        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, self::SECONDS_PER_HOUR),
            intdiv($seconds % self::SECONDS_PER_HOUR, self::SECONDS_PER_MINUTE),
            $seconds % self::SECONDS_PER_MINUTE
        );
    }
}
