<?php

namespace App\Services;

use App\Models\AttendanceCorrectRequest;
use App\Models\AttendanceRecord;

class AttendanceDetailData
{
    public function make(
        AttendanceRecord $attendance_record,
        ?AttendanceCorrectRequest $application,
        array $old_input = []
    ): array {
        $breaks = $application === null
            ? $attendance_record->breaks
            : $application->proposalBreaks;

        $data = [
            'id' => $attendance_record->id,
            'year' => $attendance_record->date->format('Y年'),
            'date' => $attendance_record->date->format('n月j日'),
            'clock_in' => $this->formatClockTime(
                $application?->new_clock_in ?? $attendance_record->clock_in
            ),
            'clock_out' => $this->formatClockTime(
                $application?->new_clock_out ?? $attendance_record->clock_out
            ),
            'breaks' => $breaks->values()->map(fn ($break_record) => [
                'break_in' => $this->formatClockTime($break_record->break_in),
                'break_out' => $this->formatClockTime($break_record->break_out),
            ])->all(),
            'comment' => $application?->comment
                ?? $attendance_record->comment
                ?? '',
            'application' => $application,
        ];

        if ($application !== null || $old_input === []) {
            return $data;
        }

        $data['clock_in'] = $this->asText($old_input['new_clock_in'] ?? '');
        $data['clock_out'] = $this->asText($old_input['new_clock_out'] ?? '');
        $data['comment'] = $this->asText($old_input['comment'] ?? '');
        $data['breaks'] = $this->restoreBreaks($old_input);

        return $data;
    }

    private function restoreBreaks(array $old_input): array
    {
        $break_starts = $old_input['new_break_in'] ?? [];
        $break_ends = $old_input['new_break_out'] ?? [];

        $break_starts = is_array($break_starts) ? $break_starts : [];
        $break_ends = is_array($break_ends) ? $break_ends : [];

        $indexes = array_unique(array_merge(
            array_keys($break_starts),
            array_keys($break_ends)
        ));
        $breaks = [];

        foreach ($indexes as $index) {
            $breaks[] = [
                'break_in' => $this->asText($break_starts[$index] ?? ''),
                'break_out' => $this->asText($break_ends[$index] ?? ''),
            ];
        }

        if ($breaks !== []) {
            $last_break = $breaks[count($breaks) - 1];

            if ($last_break['break_in'] === '' && $last_break['break_out'] === '') {
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