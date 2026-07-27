<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ReceiptExtractionService
{
    /**
     * Expected Lambda response contract (this is the interface boundary between
     * the PHP backend and the Python/Lambda AI layer — document changes to this
     * shape in both places):
     *
     * {
     *   "store_name": string,
     *   "date": "YYYY-MM-DD",
     *   "total": number,
     *   "currency": "MYR",
     *   "category": string,             // one of a fixed category list, see below
     *   "items": [{ "name": string, "quantity": number, "unit_price": number|null, "line_total": number }],
     *   "confidence": { "store_name": 0..1, "date": 0..1, "total": 0..1 }
     * }
     *
     * If the model can't confidently read a field, it should return null for
     * that field with a low confidence score — NOT guess. The controller
     * decides what to do with low-confidence fields (currently: save anyway
     * and let the user correct it, since silently rejecting uploads is worse
     * UX than an editable low-confidence result).
     */
    public const CATEGORIES = [
        'Groceries', 'Food & Drink', 'Transport', 'Health', 'Software & Subscriptions',
        'Utilities', 'Shopping', 'Entertainment', 'Uncategorized',
    ];

    public function extract(UploadedFile $image): array
    {
        $endpoint = config('services.extraction_lambda.url');
        $apiKey = config('services.extraction_lambda.key');

        if (! $endpoint) {
            throw new RuntimeException('EXTRACTION_LAMBDA_URL is not configured.');
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(28)
                ->post($endpoint, [
                    'image_base64' => base64_encode(file_get_contents($image->getRealPath())),
                    'mime_type' => $image->getMimeType(),
                ]);
        } catch (\Throwable $e) {
            Log::error('Receipt extraction request failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Could not reach the extraction service.', previous: $e);
        }

        if ($response->failed()) {
            Log::error('Receipt extraction returned an error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Extraction service returned an error.');
        }

        $data = $response->json();

        return $this->normalize($data);
    }

    /**
     * Defensive normalization — never trust the shape of an external service's
     * response as-is, even one you control. Missing fields become safe
     * defaults instead of null-pointer failures downstream.
     */
    private function normalize(?array $data): array
    {
        if (! $data) {
            throw new RuntimeException('Extraction service returned an empty response.');
        }

        $category = $data['category'] ?? 'Uncategorized';
        if (! in_array($category, self::CATEGORIES, true)) {
            $category = 'Uncategorized';
        }

        return [
            'store_name' => $data['store_name'] ?? 'Unknown store',
            'date' => $data['date'] ?? now()->toDateString(),
            'total' => (float) ($data['total'] ?? 0),
            'currency' => $data['currency'] ?? 'MYR',
            'category' => $category,
            'items' => collect($data['items'] ?? [])->map(fn ($item) => [
                'name' => $item['name'] ?? 'Item',
                'quantity' => (float) ($item['quantity'] ?? 1),
                'unit_price' => isset($item['unit_price']) ? (float) $item['unit_price'] : null,
                'line_total' => (float) ($item['line_total'] ?? 0),
            ])->all(),
            'confidence' => $data['confidence'] ?? [],
            'raw' => $data,
        ];
    }
}
