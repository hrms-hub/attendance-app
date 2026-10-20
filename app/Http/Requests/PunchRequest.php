<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PunchRequest extends FormRequest
{
    public const ACTION_CLOCK_IN = 'clock_in';

    public const ACTION_BREAK_IN = 'break_in';

    public const ACTION_BREAK_OUT = 'break_out';

    public const ACTION_CLOCK_OUT = 'clock_out';

    /**
     * 認証済みの一般ユーザーによる打刻操作を許可する。
     *
     * @return bool 一般ユーザーが認証済みの場合はtrue
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ! $user->admin_status;
    }

    /**
     * 出勤・退勤・休憩開始・休憩終了のいずれかの操作を検証する。
     *
     * @return array<string, array<int, mixed>> 打刻操作の検証規則
     */
    public function rules(): array
    {
        return [
            'action' => [
                'bail',
                'required',
                'string',
                Rule::in([
                    self::ACTION_CLOCK_IN,
                    self::ACTION_BREAK_IN,
                    self::ACTION_BREAK_OUT,
                    self::ACTION_CLOCK_OUT,
                ]),
            ],
        ];
    }

    /**
     * 打刻操作の日本語エラーメッセージを返す。
     *
     * @return array<string, string> 検証エラーのメッセージ
     */
    public function messages(): array
    {
        return [
            'action.required' => '打刻操作を選択してください',
            'action.string' => '打刻操作が不正です',
            'action.in' => '打刻操作が不正です',
        ];
    }
}
