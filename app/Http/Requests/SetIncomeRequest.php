<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetIncomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'source' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}