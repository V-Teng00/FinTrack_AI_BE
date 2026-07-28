<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\ReceiptExtractionService;

class StoreManualReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'total' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'category' => ['required', 'string', 'in:' . implode(',', ReceiptExtractionService::CATEGORIES)],
        ];
    }
}