<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AskChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:2', 'max:300'],
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }
}
