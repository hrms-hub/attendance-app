<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AttendanceCorrectionRequest extends FormRequest
{
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
            'comment' => ['bail', 'required', 'string', 'max:255'],
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
            'comment.max' => '備考は255文字以内で入力してください',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $clock_in = $this->input('new_clock_in');
                $clock_out = $this->input('new_clock_out');

                if ($clock_out < $clock_in) {
                    $validator->errors()->add(
                        'new_clock_out',
                        self::CLOCK_ERROR
                    );

                    return;
                }

                $this->validateBreaks($validator, $clock_in, $clock_out);
            },
        ];
    }

    private function validateBreaks(
        Validator $validator,
        string $clock_in,
        string $clock_out
    ): void {
        $break_starts = $this->input('new_break_in', []);
        $break_ends = $this->input('new_break_out', []);
        $indexes = array_unique(array_merge(
            array_keys($break_starts),
            array_keys($break_ends)
        ));
        $intervals = [];

        foreach ($indexes as $index) {
            $break_in = $break_starts[$index] ?? '';
            $break_out = $break_ends[$index] ?? '';

            if ($break_in === '' && $break_out === '') {
                continue;
            }

            if ($break_in === '' || $break_out === '') {
                $error_key = $break_in === ''
                    ? "new_break_in.{$index}"
                    : "new_break_out.{$index}";

                $validator->errors()->add($error_key, self::BREAK_ERROR);
                continue;
            }

            if ($break_in < $clock_in || $break_in > $clock_out) {
                $validator->errors()->add(
                    "new_break_in.{$index}",
                    self::BREAK_ERROR
                );
                continue;
            }

            if ($break_out > $clock_out) {
                $validator->errors()->add(
                    "new_break_out.{$index}",
                    self::BREAK_END_ERROR
                );
                continue;
            }

            if ($break_out < $break_in) {
                $validator->errors()->add(
                    "new_break_out.{$index}",
                    self::BREAK_ERROR
                );
                continue;
            }

            if ($break_in === $break_out) {
                continue;
            }

            foreach ($intervals as [$previous_in, $previous_out]) {
                if ($break_in < $previous_out && $previous_in < $break_out) {
                    $validator->errors()->add(
                        "new_break_in.{$index}",
                        self::BREAK_ERROR
                    );
                    break;
                }
            }

            $intervals[] = [$break_in, $break_out];
        }
    }
}