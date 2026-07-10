<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by auth:sanctum middleware at the route level
    }

    public function rules(): array
    {
        return [
            // 8MB cap — phone camera photos routinely hit 4-6MB; reject early
            // rather than let a huge upload sit in a Lambda payload
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Attach a receipt image.',
            'image.image' => 'The file must be an image.',
            'image.mimes' => 'Only JPG, PNG, or WEBP images are supported.',
            'image.max' => 'Image must be under 8MB.',
        ];
    }
}
