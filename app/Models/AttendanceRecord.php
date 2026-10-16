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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(BreakRecord::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(AttendanceCorrectRequest::class);
    }

    protected function totalBreakTime(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->breaks->whereNotNull('break_out')->isEmpty()) {
                    return null;
                }

                return $this->formatDuration($this->totalBreakSeconds());
            }
        );
    }

    protected function totalTime(): Attribute
    {
        return Attribute::make(
            get: function () {
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

    private function totalBreakSeconds(): int
    {
        $totalSeconds = 0;

        foreach ($this->breaks->whereNotNull('break_out') as $breakRecord) {
            $totalSeconds += $this->timeToTimestamp($breakRecord->break_out)
                - $this->timeToTimestamp($breakRecord->break_in);
        }

        return $totalSeconds;
    }

    private function timeToTimestamp(string $time): int
    {
        $date = $this->date->format('Y-m-d');

        return Carbon::parse($date.' '.$time, 'Asia/Tokyo')
            ->getTimestamp();
    }

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
