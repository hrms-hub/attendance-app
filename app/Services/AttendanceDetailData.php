<?php

namespace App\Services;

use App\Models\AttendanceCorrectRequest;
use App\Models\AttendanceRecord;

class AttendanceDetailData
{
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
            'breaks' => $breaks->values()->map(fn ($breakRecord) => [
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
        $breaks = [];

        foreach ($indexes as $index) {
            $breaks[] = [
                'break_in' => $this->asText($breakStarts[$index] ?? ''),
                'break_out' => $this->asText($breakEnds[$index] ?? ''),
            ];
        }

        if ($breaks !== []) {
            $lastBreak = $breaks[count($breaks) - 1];

            if ($lastBreak['break_in'] === '' && $lastBreak['break_out'] === '') {
                array_pop($breaks);
            }
        }

        return $breaks;
    }

    private function formatClockTime(?string $time): string
    {
        return $time === null ? '' : substr($time, 0, 5);
    }

    private function asText(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
