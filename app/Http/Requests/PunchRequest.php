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

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && !$user->admin_status;
    }

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

    public function messages(): array
    {
        return [
            'action.required' => '打刻操作を選択してください',
            'action.string' => '打刻操作が不正です',
            'action.in' => '打刻操作が不正です',
        ];
    }
}