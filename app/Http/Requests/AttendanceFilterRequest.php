<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AttendanceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $dateFormat = $this->is('admin/attendance/list')
            ? 'Y-m-d'
            : 'Y-m';

        return [
            'date' => ['nullable', 'date_format:'.$dateFormat],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect($this->url())
        );
    }
}
