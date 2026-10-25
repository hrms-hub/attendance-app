<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AttendanceRecord;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAttendanceRecordRequest extends StoreAttendanceRecordRequest
{
    /**
     * 入力検証の前に、対象勤怠の更新権限を確認する。
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('attendanceRecord')) ?? false;
    }

    /**
     * 送信された項目だけを対象勤怠の所有者と既存値に基づいて検証する。
     *
     * @return array<string, array<int, mixed>> 部分更新の検証規則
     */
    public function rules(): array
    {
        /** @var AttendanceRecord $attendanceRecord */
        $attendanceRecord = $this->route('attendanceRecord');

        return [
            'date' => [
                'bail',
                'sometimes',
                'required',
                'date_format:Y-m-d',
                Rule::unique('attendance_records', 'date')
                    ->where('user_id', $attendanceRecord->user_id)
                    ->ignore($attendanceRecord),
            ],
            'clock_in' => ['bail', 'sometimes', 'required', 'date_format:H:i:s'],
            'clock_out' => ['bail', 'sometimes', 'nullable', 'date_format:H:i:s'],
            'comment' => ['bail', 'sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * 時刻を変更する場合に、未送信の時刻を既存値で補って前後関係を検証する。
     *
     * @param  Validator  $validator  入力内容の検証処理
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                ! $this->hasAny(['clock_in', 'clock_out'])
                || $validator->errors()->hasAny(['clock_in', 'clock_out'])
            ) {
                return;
            }

            /** @var AttendanceRecord $attendanceRecord */
            $attendanceRecord = $this->route('attendanceRecord');

            $clockIn = $this->input('clock_in', $attendanceRecord->clock_in);
            $clockOut = $this->input('clock_out', $attendanceRecord->clock_out);

            if ($clockOut !== null && $clockOut <= $clockIn) {
                $validator->errors()->add('clock_out', $this->messages()['clock_out.after']);
            }
        });
    }
}
