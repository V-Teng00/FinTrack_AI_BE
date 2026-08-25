<?php

namespace App\Http\Requests;

use App\Services\ReceiptExtractionService;
use Illuminate\Foundation\Http\FormRequest;

class SetGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'in:' . implode(',', ReceiptExtractionService::CATEGORIES)],
            'monthly_limit' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
        ];
    }
}