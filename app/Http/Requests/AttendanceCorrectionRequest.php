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

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $comment = $this->input('comment');

        if (is_string($comment)) {
            $this->merge([
                'comment' => preg_replace('/\A[\s　]+|[\s　]+\z/u', '', $comment),
            ]);
        }
    }

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

        foreach ($indexes as $index) {
            if (
                $validator->errors()->has("new_break_in.{$index}") || $validator->errors()->has("new_break_out.{$index}")
            ) {
                continue;
            }

            $breakIn = $breakStarts[$index] ?? '';
            $breakOut = $breakEnds[$index] ?? '';

            if ($breakIn === '' && $breakOut === '') {
                continue;
            }

            if ($breakIn === '' || $breakOut === '') {
                $errorKey = $breakIn === ''
                    ? "new_break_in.{$index}"
                    : "new_break_out.{$index}";

                $validator->errors()->add($errorKey, self::BREAK_ERROR);

                continue;
            }

            if ($breakIn < $clockIn || $breakIn > $clockOut) {
                $validator->errors()->add(
                    "new_break_in.{$index}",
                    self::BREAK_ERROR
                );

                continue;
            }

            if ($breakOut > $clockOut) {
                $validator->errors()->add(
                    "new_break_out.{$index}",
                    self::BREAK_END_ERROR
                );

                continue;
            }

            if ($breakOut < $breakIn) {
                $validator->errors()->add(
                    "new_break_out.{$index}",
                    self::BREAK_ERROR
                );

                continue;
            }

            if ($breakIn === $breakOut) {
                continue;
            }

            if ($this->hasOverlappingBreak($intervals, $breakIn, $breakOut)) {
                $validator->errors()->add(
                    "new_break_in.{$index}",
                    self::BREAK_ERROR
                );
            }

            $intervals[] = [$breakIn, $breakOut];
        }
    }

    private function hasOverlappingBreak(
        array $intervals,
        string $breakIn,
        string $breakOut
    ): bool {
        foreach ($intervals as [$previousIn, $previousOut]) {
            if ($breakIn < $previousOut && $previousIn < $breakOut) {
                return true;
            }
        }

        return false;
    }
}
