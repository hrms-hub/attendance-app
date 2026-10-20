<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AttendanceFilterRequest extends FormRequest
{
    /**
     * 認証済みユーザーによる勤怠一覧の検索を許可する。
     *
     * @return bool 認証済みの場合はtrue
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * 日次一覧は日付、月次一覧は年月の形式で検索条件を検証する。
     *
     * @return array<string, array<int, string>> 検索条件の検証規則
     */
    public function rules(): array
    {
        $dateFormat = $this->is('admin/attendance/list')
            ? 'Y-m-d'
            : 'Y-m';

        return [
            'date' => ['nullable', 'date_format:'.$dateFormat],
        ];
    }

    /**
     * 不正な検索条件の場合は検索条件を外して同じ画面へ戻す。
     *
     * @param  Validator  $validator  失敗した検証処理
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect($this->url())
        );
    }
}
