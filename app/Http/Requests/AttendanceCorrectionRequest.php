<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AttendanceCorrectionRequest extends FormRequest
{
    private const MAX_COMMENT_LENGTH = 255;

    private const CLOCK_ERROR = '出勤時間もしくは退勤時間が不適切な値です';

    private const BREAK_ERROR = '休憩時間が不適切な値です';

    private const BREAK_END_ERROR = '休憩時間もしくは退勤時間が不適切な値です';

    /**
     * 認証済みユーザーによる修正入力を許可する。
     *
     * @return bool 認証済みの場合はtrue
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * 備考の前後にある半角・全角空白を検証前に除去する。
     */
    protected function prepareForValidation(): void
    {
        $comment = $this->input('comment');

        if (is_string($comment)) {
            $this->merge([
                'comment' => preg_replace('/\A[\s　]+|[\s　]+\z/u', '', $comment),
            ]);
        }
    }

    /**
     * 出退勤・休憩・備考の基本的な入力規則を定義する。
     *
     * @return array<string, array<int, string>> 項目ごとの検証規則
     */
    public function rules(): array
    {
        return [
            'new_clock_in' => ['bail', 'required', 'date_format:H:i'],
            'new_clock_out' => ['bail', 'required', 'date_format:H:i'],
            'new_break_in' => ['sometimes', 'array'],
            'new_break_out' => ['sometimes', 'array'],
            'new_break_in.*' => ['bail', 'nullable', 'date_format:H:i'],
            'new_break_out.*' => ['bail', 'nullable', 'date_format:H:i'],
            'comment' => ['bail', 'required', 'string', 'max:'.self::MAX_COMMENT_LENGTH],
        ];
    }

    /**
     * 入力規則に対応する日本語エラーメッセージを返す。
     *
     * @return array<string, string> 検証エラーのメッセージ
     */
    public function messages(): array
    {
        return [
            'new_clock_in.required' => self::CLOCK_ERROR,
            'new_clock_in.date_format' => self::CLOCK_ERROR,
            'new_clock_out.required' => self::CLOCK_ERROR,
            'new_clock_out.date_format' => self::CLOCK_ERROR,
            'new_break_in.array' => self::BREAK_ERROR,
            'new_break_out.array' => self::BREAK_ERROR,
            'new_break_in.*.date_format' => self::BREAK_ERROR,
            'new_break_out.*.date_format' => self::BREAK_ERROR,
            'comment.required' => '備考を記入してください',
            'comment.string' => '備考は文字列で入力してください',
            'comment.max' => '備考は'.self::MAX_COMMENT_LENGTH.'文字以内で入力してください',
        ];
    }

    /**
     * 基本検証後に勤務時間と休憩時間の前後関係を検証する。
     *
     * @return array<int, callable> 追加検証のコールバック
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $validator->errors()->has('new_clock_in')
                    || $validator->errors()->has('new_clock_out')
                ) {
                    return;
                }

                $clockIn = $this->input('new_clock_in');
                $clockOut = $this->input('new_clock_out');

                if ($clockOut < $clockIn) {
                    $validator->errors()->add(
                        'new_clock_out',
                        self::CLOCK_ERROR
                    );

                    return;
                }

                if (
                    $validator->errors()->has('new_break_in')
                    || $validator->errors()->has('new_break_out')
                ) {
                    return;
                }

                $this->validateBreaks($validator, $clockIn, $clockOut);
            },
        ];
    }

    /**
     * 各休憩の入力漏れ、勤務時間外の指定、前後関係と重複を検証する。
     *
     * @param  Validator  $validator  検証エラーの追加先
     * @param  string  $clockIn  出勤時刻（HH:MM）
     * @param  string  $clockOut  退勤時刻（HH:MM）
     */
    private function validateBreaks(
        Validator $validator,
        string $clockIn,
        string $clockOut
    ): void {
        $breakStarts = $this->input('new_break_in', []);
        $breakEnds = $this->input('new_break_out', []);
        $indexes = array_unique(array_merge(
            array_keys($breakStarts),
            array_keys($breakEnds)
        ));
        $intervals = [];

        collect($indexes)->each(function (int|string $index) use (
            $validator,
            $clockIn,
            $clockOut,
            $breakStarts,
            $breakEnds,
            &$intervals
        ): void {
            if (
                $validator->errors()->has("new_break_in.{$index}") || $validator->errors()->has("new_break_out.{$index}")
            ) {
                return;
            }

            $breakIn = $breakStarts[$index] ?? '';
            $breakOut = $breakEnds[$index] ?? '';

            if ($breakIn === '' && $breakOut === '') {
                return;
            }

            if ($breakIn === '' || $breakOut === '') {
                $errorKey = $breakIn === ''
                    ? "new_break_in.{$index}"
                    : "new_break_out.{$index}";

                $validator->errors()->add($errorKey, self::BREAK_ERROR);

                return;
            }

            if ($breakIn < $clockIn || $breakIn > $clockOut) {
                $validator->errors()->add(
                    "new_break_in.{$index}",
                    self::BREAK_ERROR
                );

                return;
            }

            if ($breakOut > $clockOut) {
                $validator->errors()->add(
                    "new_break_out.{$index}",
                    self::BREAK_END_ERROR
                );

                return;
            }

            if ($breakOut < $breakIn) {
                $validator->errors()->add(
                    "new_break_out.{$index}",
                    self::BREAK_ERROR
                );

                return;
            }

            if ($breakIn === $breakOut) {
                return;
            }

            if ($this->hasOverlappingBreak($intervals, $breakIn, $breakOut)) {
                $validator->errors()->add(
                    "new_break_in.{$index}",
                    self::BREAK_ERROR
                );
            }

            $intervals[] = [$breakIn, $breakOut];
        });
    }

    /**
     * 指定休憩が、それまでの休憩区間と重なるかを判定する。
     *
     * @param  array<int, array{0: string, 1: string}>  $intervals  それまでの休憩区間
     * @param  string  $breakIn  判定対象の休憩開始時刻
     * @param  string  $breakOut  判定対象の休憩終了時刻
     * @return bool 時間帯が重複する場合はtrue
     */
    private function hasOverlappingBreak(
        array $intervals,
        string $breakIn,
        string $breakOut
    ): bool {
        return collect($intervals)->contains(
            fn (array $interval): bool => $breakIn < $interval[1]
                && $interval[0] < $breakOut
        );
    }
}
