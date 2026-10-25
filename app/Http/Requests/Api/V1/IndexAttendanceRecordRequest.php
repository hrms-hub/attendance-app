<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class IndexAttendanceRecordRequest extends FormRequest
{
    /**
     * 未認証の利用者にも勤怠の検索を許可する。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 検索条件とページネーションの入力を検証する。
     *
     * @return array<string, array<int, string>> 検索条件の検証規則
     */
    public function rules(): array
    {
        return [
            'user_id' => ['bail', 'nullable', 'integer', 'min:1'],
            'date' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'month' => ['bail', 'nullable', 'date_format:Y-m'],
            'page' => ['bail', 'nullable', 'integer', 'min:1'],
            'per_page' => ['bail', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * 検索入力の日本語エラーメッセージを返す。
     *
     * @return array<string, string> 検証エラーのメッセージ
     */
    public function messages(): array
    {
        return [
            'user_id.integer' => 'ユーザーIDは整数で指定してください。',
            'user_id.min' => 'ユーザーIDは 1 以上で指定してください。',
            'date.date_format' => '勤怠日は YYYY-MM-DD 形式で指定してください。',
            'month.date_format' => '年月は YYYY-MM 形式で指定してください。',
            'page.integer' => 'ページ番号は整数で指定してください。',
            'page.min' => 'ページ番号は 1 以上で指定してください。',
            'per_page.integer' => '1ページあたりの件数は整数で指定してください。',
            'per_page.min' => '1ページあたりの件数は 1 以上で指定してください。',
            'per_page.max' => '1ページあたりの件数は 100 以下で指定してください。',
        ];
    }
}
