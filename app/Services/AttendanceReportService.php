<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonImmutable;

class AttendanceReportService
{
    private const MONTH_COUNT = 6;

    private const SECONDS_PER_MINUTE = 60;

    private const SECONDS_PER_HOUR = 3600;

    private const STANDARD_WORK_SECONDS = 8 * self::SECONDS_PER_HOUR;

    private const LONG_WORK_SECONDS = 10 * self::SECONDS_PER_HOUR;

    /**
     * 指定ユーザーの6か月分の勤怠統計を作成する。
     *
     * @param  User  $user  集計対象ユーザー
     * @param  CarbonImmutable  $referenceDate  集計基準日
     * @return array<string, mixed> サマリー・月次推移・異常検知
     */
    public function generate(User $user, CarbonImmutable $referenceDate): array
    {
        $currentMonth = $referenceDate->startOfMonth();
        $firstMonth = $currentMonth->subMonths(self::MONTH_COUNT - 1);

        // 休憩を一括取得し、既存モデルの実働時間を利用する
        $records = $user->attendanceRecords()
            ->with('breaks')
            ->whereBetween('date', [
                $firstMonth->toDateString(),
                $currentMonth->endOfMonth()->toDateString(),
            ])
            ->get()
            ->map(function (AttendanceRecord $record): array {
                $duration = $record->total_time;

                $workSeconds = $duration === null
                    ? null
                    : $this->durationToSeconds($duration);

                return [
                    'month' => $record->date->format('Y-m'),
                    'clock_in' => $record->clock_in,
                    'clock_out' => $record->clock_out,
                    'work_seconds' => $workSeconds,
                    'overtime_seconds' => $workSeconds === null
                        ? 0
                        : max(0, $workSeconds - self::STANDARD_WORK_SECONDS),
                ];
            });

        $completedRecords = $records->filter(
            fn (array $record): bool => $record['work_seconds'] !== null
        );

        $totalWorkSeconds = (int) $completedRecords->sum('work_seconds');
        $totalOvertimeSeconds = (int) $completedRecords->sum('overtime_seconds');

        $summary = [
            'total_work_minutes' => intdiv(
                $totalWorkSeconds,
                self::SECONDS_PER_MINUTE
            ),
            'total_overtime_minutes' => intdiv(
                $totalOvertimeSeconds,
                self::SECONDS_PER_MINUTE
            ),
            'avg_work_minutes' => $completedRecords->isEmpty()
                ? 0
                : intdiv(
                    $totalWorkSeconds,
                    $completedRecords->count() * self::SECONDS_PER_MINUTE
                ),
        ];

        $groupedRecords = $completedRecords->groupBy('month');

        $monthlyTrend = collect(range(0, self::MONTH_COUNT - 1))
            ->map(function (int $offset) use ($firstMonth, $groupedRecords): array {
                $month = $firstMonth->addMonths($offset)->format('Y-m');
                $monthRecords = $groupedRecords->get($month, collect());

                return [
                    'month' => $month,
                    'work_minutes' => intdiv(
                        (int) $monthRecords->sum('work_seconds'),
                        self::SECONDS_PER_MINUTE
                    ),
                    'overtime_minutes' => intdiv(
                        (int) $monthRecords->sum('overtime_seconds'),
                        self::SECONDS_PER_MINUTE
                    ),
                ];
            })
            ->all();

        $currentRecords = $records->where(
            'month',
            $currentMonth->format('Y-m')
        );

        $anomalies = [
            'late_count' => $currentRecords->filter(
                fn (array $record): bool => $record['clock_in'] > '09:00:00'
            )->count(),
            'early_leave_count' => $currentRecords->filter(
                fn (array $record): bool => $record['clock_out'] !== null
                    && $record['clock_out'] < '18:00:00'
            )->count(),
            'long_work_count' => $currentRecords->filter(
                fn (array $record): bool => $record['work_seconds'] !== null
                    && $record['work_seconds'] > self::LONG_WORK_SECONDS
            )->count(),
        ];

        return [
            'summary' => $summary,
            'monthly_trend' => $monthlyTrend,
            'anomalies' => $anomalies,
        ];
    }

    /**
     * モデルの経過時間を秒数に変換する。
     *
     * @param  string  $duration  HH:MM:SS形式の経過時間
     * @return int 経過秒数
     */
    private function durationToSeconds(string $duration): int
    {
        [$hours, $minutes, $seconds] = array_map(
            'intval',
            explode(':', $duration)
        );

        return $hours * self::SECONDS_PER_HOUR
            + $minutes * self::SECONDS_PER_MINUTE
            + $seconds;
    }
}
