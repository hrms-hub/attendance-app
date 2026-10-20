<?php

namespace App\Services;

use App\Models\AttendanceCorrectRequest;
use App\Models\AttendanceRecord;
use App\Models\BreakRecord;
use App\Models\ProposalBreak;

class AttendanceDetailData
{
    /**
     * 勤怠・修正申請・再入力値から詳細画面の表示データを組み立てる。
     *
     * @param  AttendanceRecord  $attendanceRecord  表示対象の勤怠
     * @param  AttendanceCorrectRequest|null  $application  表示対象の修正申請
     * @param  array<string, mixed>  $oldInput  検証エラー後の再入力値
     * @return array<string, mixed> 詳細画面へ渡す表示データ
     */
    public function make(
        AttendanceRecord $attendanceRecord,
        ?AttendanceCorrectRequest $application,
        array $oldInput = []
    ): array {
        $breaks = $application === null
            ? $attendanceRecord->breaks
            : $application->proposalBreaks;

        $data = [
            'id' => $attendanceRecord->id,
            'year' => $attendanceRecord->date->format('Y年'),
            'date' => $attendanceRecord->date->format('n月j日'),
            'clock_in' => $this->formatClockTime(
                $application?->new_clock_in ?? $attendanceRecord->clock_in
            ),
            'clock_out' => $this->formatClockTime(
                $application?->new_clock_out ?? $attendanceRecord->clock_out
            ),
            'breaks' => $breaks->values()->map(fn (BreakRecord|ProposalBreak $breakRecord): array => [
                'break_in' => $this->formatClockTime($breakRecord->break_in),
                'break_out' => $this->formatClockTime($breakRecord->break_out),
            ])->all(),
            'comment' => $application?->comment
                ?? $attendanceRecord->comment
                ?? '',
            'application' => $application,
        ];

        if ($application !== null || $oldInput === []) {
            return $data;
        }

        $data['clock_in'] = $this->asText($oldInput['new_clock_in'] ?? '');
        $data['clock_out'] = $this->asText($oldInput['new_clock_out'] ?? '');
        $data['comment'] = $this->asText($oldInput['comment'] ?? '');
        $data['breaks'] = $this->restoreBreaks($oldInput);

        return $data;
    }

    /**
     * 再入力値から休憩行を復元し、末尾の空欄行を除去する。
     *
     * @param  array<string, mixed>  $oldInput  検証エラー後の再入力値
     * @return array<int, array{break_in: string, break_out: string}> 復元した休憩行
     */
    private function restoreBreaks(array $oldInput): array
    {
        $breakStarts = $oldInput['new_break_in'] ?? [];
        $breakEnds = $oldInput['new_break_out'] ?? [];

        $breakStarts = is_array($breakStarts) ? $breakStarts : [];
        $breakEnds = is_array($breakEnds) ? $breakEnds : [];

        $indexes = array_unique(array_merge(
            array_keys($breakStarts),
            array_keys($breakEnds)
        ));
        $breaks = collect($indexes)
            ->map(fn (int|string $index): array => [
                'break_in' => $this->asText($breakStarts[$index] ?? ''),
                'break_out' => $this->asText($breakEnds[$index] ?? ''),
            ])
            ->values()
            ->all();

        if ($breaks !== []) {
            $lastBreak = $breaks[count($breaks) - 1];

            if ($lastBreak['break_in'] === '' && $lastBreak['break_out'] === '') {
                array_pop($breaks);
            }
        }

        return $breaks;
    }

    /**
     * 打刻時刻を時間と分に整形する。
     *
     * @param  string|null  $time  HH:MM:SS形式の時刻
     * @return string HH:MM形式の時刻。未打刻の場合は空文字
     */
    private function formatClockTime(?string $time): string
    {
        return $time === null ? '' : substr($time, 0, 5);
    }

    /**
     * 入力値が文字列の場合にだけ表示用の値として採用する。
     *
     * @param  mixed  $value  確認する入力値
     * @return string 入力された文字列。文字列以外の場合は空文字
     */
    private function asText(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
