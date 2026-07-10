<?php

namespace App\Services;

use App\Models\Receipt;
use App\Services\ReceiptExtractionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatQueryService
{
    /**
     * Fixed intent list. The LLM's only job is to pick one of these and fill
     * in parameters — it never writes a query and never sees the database.
     * Adding a new question type means adding an intent here AND a handler
     * below, not loosening what the model is allowed to output.
     */
    private const INTENTS = [
        'category_spend',   // "how much did I spend on food"
        'total_spend',      // "how much have I spent this month"
        'top_category',     // "what's my biggest spending category"
        'savings',          // "how much have I saved"
        'receipt_count',    // "how many receipts do I have"
        'unknown',
    ];

    public function __construct(
        private readonly int $userId,
    ) {}

    public function ask(string $message, ?string $month = null): string
    {
        $month ??= now()->format('Y-m');

        $intent = $this->classifyIntent($message, $month);

        return match ($intent['intent']) {
            'category_spend' => $this->handleCategorySpend($intent, $month),
            'total_spend' => $this->handleTotalSpend($month),
            'top_category' => $this->handleTopCategory($month),
            'savings' => $this->handleSavings($month),
            'receipt_count' => $this->handleReceiptCount($month),
            default => $this->handleUnknown(),
        };
    }

    /**
     * Calls the LLM with a strict instruction to return ONLY a JSON object
     * shaped { "intent": ..., "category": string|null }. Falls back to
     * "unknown" on any parse failure or disallowed value — fail closed, not
     * open. This can reuse the same Lambda/LLM endpoint pattern as receipt
     * extraction, or call the provider API directly; either way, this is the
     * ONLY place natural language touches the classification step.
     */
    private function classifyIntent(string $message, string $month): array
    {
        $endpoint = config('services.chat_llm.url');
        $apiKey = config('services.chat_llm.key');

        $categories = implode(', ', ReceiptExtractionService::CATEGORIES);

        $systemPrompt = <<<PROMPT
        Classify the user's question about their personal expenses into exactly
        one intent. Respond with ONLY a JSON object, no other text:

        {"intent": "<one of: category_spend, total_spend, top_category, savings, receipt_count, unknown>",
         "category": "<one of: {$categories}, or null>"}

        Known categories: {$categories}
        If the question doesn't clearly match one of these intents, use "unknown".
        Never include commentary, explanation, or markdown — JSON only.
        PROMPT;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(15)
                ->post($endpoint, [
                    'system' => $systemPrompt,
                    'message' => $message,
                ]);

            $raw = $response->json('reply') ?? $response->json('content') ?? $response->body();
            $parsed = json_decode(trim($raw), true);
        } catch (\Throwable $e) {
            Log::warning('Chat intent classification failed', ['error' => $e->getMessage()]);
            $parsed = null;
        }

        $intent = $parsed['intent'] ?? 'unknown';
        if (! in_array($intent, self::INTENTS, true)) {
            $intent = 'unknown';
        }

        $category = $parsed['category'] ?? null;
        if ($category !== null && ! in_array($category, ReceiptExtractionService::CATEGORIES, true)) {
            $category = null;
        }

        return ['intent' => $intent, 'category' => $category];
    }

    private function handleCategorySpend(array $intent, string $month): string
    {
        if (! $intent['category']) {
            return "Which category did you mean? For example: Groceries, Food & Drink, Transport, Health.";
        }

        $total = Receipt::where('user_id', $this->userId)
            ->inMonth($month)
            ->inCategory($intent['category'])
            ->sum('total');

        $count = Receipt::where('user_id', $this->userId)
            ->inMonth($month)
            ->inCategory($intent['category'])
            ->count();

        if ($count === 0) {
            return "You have no {$intent['category']} receipts recorded for {$month}.";
        }

        return sprintf(
            "You've spent RM%s on %s this month, across %d receipt%s.",
            number_format((float) $total, 2),
            $intent['category'],
            $count,
            $count === 1 ? '' : 's'
        );
    }

    private function handleTotalSpend(string $month): string
    {
        $total = Receipt::where('user_id', $this->userId)->inMonth($month)->sum('total');

        return sprintf("You've spent RM%s in total this month.", number_format((float) $total, 2));
    }

    private function handleTopCategory(string $month): string
    {
        $row = Receipt::where('user_id', $this->userId)
            ->inMonth($month)
            ->selectRaw('category, SUM(total) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->first();

        if (! $row) {
            return "You don't have any receipts recorded for {$month} yet.";
        }

        return sprintf(
            "Your top spending category this month is %s at RM%s.",
            $row->category,
            number_format((float) $row->total, 2)
        );
    }

    private function handleSavings(string $month): string
    {
        $income = (float) (\App\Models\Income::where('user_id', $this->userId)
            ->where('month', $month)
            ->value('amount') ?? 0);

        $spending = (float) Receipt::where('user_id', $this->userId)->inMonth($month)->sum('total');
        $savings = $income - $spending;

        if ($income <= 0) {
            return "You haven't set an income for {$month} yet, so I can't calculate savings — but you've spent RM"
                . number_format($spending, 2) . ' so far this month.';
        }

        $rate = round(($savings / $income) * 100);

        return sprintf(
            "You've saved RM%s this month (%d%% of income). Income: RM%s, spending: RM%s.",
            number_format($savings, 2),
            $rate,
            number_format($income, 2),
            number_format($spending, 2)
        );
    }

    private function handleReceiptCount(string $month): string
    {
        $count = Receipt::where('user_id', $this->userId)->inMonth($month)->count();

        return "You have {$count} receipt" . ($count === 1 ? '' : 's') . " recorded for {$month}.";
    }

    private function handleUnknown(): string
    {
        return "I can answer questions about your spending — try things like "
            . '"how much did I spend on food this month" or "what\'s my biggest category?"';
    }
}
