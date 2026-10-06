<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    private const MAX_TEXT_LENGTH = 255;
    private const MIN_PASSWORD_LENGTH = 8;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'bail',
                'required',
                'string',
                'max:' . self::MAX_TEXT_LENGTH,
            ],
            'email' => [
                'bail',
                'required',
                'string',
                'email',
                'max:' . self::MAX_TEXT_LENGTH,
                'unique:users,email',
            ],
            'password' => [
                'bail',
                'required',
                'string',
                'min:' . self::MIN_PASSWORD_LENGTH,
            ],
            'password_confirmation' => [
                'bail',
                'required_with:password',
                'string',
                'same:password',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'お名前を入力してください',
            'name.string' => 'お名前は文字列で入力してください',
            'name.max' => 'お名前は' . self::MAX_TEXT_LENGTH . '文字以内で入力してください',

            'email.required' => 'メールアドレスを入力してください',
            'email.string' => 'メールアドレスはメール形式で入力してください',
            'email.email' => 'メールアドレスはメール形式で入力してください',
            'email.max' => 'メールアドレスは' . self::MAX_TEXT_LENGTH . '文字以内で入力してください',
            'email.unique' => 'このメールアドレスは既に登録されています',

            'password.required' => 'パスワードを入力してください',
            'password.string' => 'パスワードは文字列で入力してください',
            'password.min' => 'パスワードは' . self::MIN_PASSWORD_LENGTH . '文字以上で入力してください',

            'password_confirmation.required_with' => 'パスワードと一致しません',
            'password_confirmation.string' => 'パスワードと一致しません',
            'password_confirmation.same' => 'パスワードと一致しません',
        ];
    }
}