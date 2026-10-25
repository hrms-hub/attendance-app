<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRecordRequest extends FormRequest
{
    /**
     * Sanctumで認証済みのユーザーによる登録を許可する。
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * 認証ユーザーの日付重複と出退勤の入力を検証する。
     *
     * @return array<string, array<int, mixed>> 登録内容の検証規則
     */
    public function rules(): array
    {
        return [
            'date' => [
                'bail',
                'required',
                'date_format:Y-m-d',
                Rule::unique('attendance_records', 'date')
                    ->where('user_id', $this->user()->getAuthIdentifier()),
            ],
            'clock_in' => ['bail', 'required', 'date_format:H:i:s'],
            'clock_out' => ['bail', 'nullable', 'date_format:H:i:s', 'after:clock_in'],
            'comment' => ['bail', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * 登録・更新で共通の日本語エラーメッセージを返す。
     *
     * @return array<string, string> 検証エラーのメッセージ
     */
    public function messages(): array
    {
        return [
            'date.required' => '勤怠日は必須です。',
            'date.date_format' => '勤怠日は YYYY-MM-DD 形式で指定してください。',
            'date.unique' => 'この日付の勤怠は既に登録されています。',
            'clock_in.required' => '出勤時刻は必須です。',
            'clock_in.date_format' => '出勤時刻は HH:MM:SS 形式で指定してください。',
            'clock_out.date_format' => '退勤時刻は HH:MM:SS 形式で指定してください。',
            'clock_out.after' => '退勤時刻は出勤時刻より後の時刻を指定してください。',
            'comment.string' => '備考は文字列で入力してください。',
            'comment.max' => '備考は 255 文字以内で入力してください。',
        ];
    }
}
