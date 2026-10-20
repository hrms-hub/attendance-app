<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceExportRequest extends FormRequest
{
    /**
     * 管理者によるCSV出力を許可する。
     */
    public function authorize(): bool
    {
        return $this->user()?->admin_status === true;
    }

    /**
     * 出力対象のスタッフと月を検証する。
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('users', 'id')->where('admin_status', false),
            ],
            'year_month' => [
                'bail',
                'required',
                'string',
                'date_format:Y-m',
            ],
        ];
    }

    /**
     * 入力エラーの日本語メッセージを返す。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'スタッフを指定してください。',
            'user_id.integer' => 'スタッフの指定が不正です。',
            'user_id.exists' => '対象のスタッフが存在しません。',
            'year_month.required' => '対象月を指定してください。',
            'year_month.string' => '対象月はYYYY-MM形式で指定してください。',
            'year_month.date_format' => '対象月はYYYY-MM形式で指定してください。',
        ];
    }
}
