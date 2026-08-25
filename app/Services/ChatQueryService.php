<?php

namespace App\Services;

use App\Models\Receipt;
use App\Services\ReceiptExtractionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatQueryService
{
    private const INTENTS = [
        'category_spend', 'total_spend', 'top_category',
        'savings', 'receipt_count', 'recommendation', 'add_expense', 'unknown',
    ];

    private const CONFIRM_WORDS = ['yes', 'y', 'confirm', 'ok', 'okay', 'add it', 'do it', 'yeah', 'yep', 'sure', 'confirmed'];
    private const CANCEL_WORDS = ['no', 'n', 'cancel', 'nevermind', 'never mind', 'stop', 'don\'t', 'dont'];

    public function __construct(
        private readonly int $userId,
    ) {}

    public function ask(string $message, ?string $month = null): string
    {
        // Confirm/cancel replies NEVER go through the LLM — this is the
        // guardrail. Whether a write happens is decided here, in plain
        // code, against a fixed word list. The model never sees this step.
        $pendingResponse = $this->handlePendingAction($message);
        if ($pendingResponse !== null) {
            return $pendingResponse;
        }

        $intent = $this->classifyIntent($message);

        $effectiveMonth = $intent['month'] ?? $month ?? now()->format('Y-m');

        return match ($intent['intent']) {
            'category_spend' => $this->handleCategorySpend($intent, $effectiveMonth),
            'total_spend' => $this->handleTotalSpend($effectiveMonth),
            'top_category' => $this->handleTopCategory($effectiveMonth),
            'savings' => $this->handleSavings($effectiveMonth),
            'receipt_count' => $this->handleReceiptCount($effectiveMonth),
            'recommendation' => $this->handleRecommendation($intent, $effectiveMonth),
            'add_expense' => $this->handleAddExpense($intent),
            default => $this->handleUnknown(),
        };
    }

    private function cacheKey(): string
    {
        return "chat_pending_action:{$this->userId}";
    }

    /**
     * Returns a response string if this message was handled as a
     * confirm/cancel of a pending action, or null if there's no pending
     * action (caller should proceed to normal classification).
     */
    private function handlePendingAction(string $message): ?string
    {
        $pending = Cache::get($this->cacheKey());
        if (! $pending) {
            return null;
        }

        $normalized = strtolower(trim($message));

        if (in_array($normalized, self::CANCEL_WORDS, true)) {
            Cache::forget($this->cacheKey());
            return "Cancelled — nothing was added.";
        }

        if (in_array($normalized, self::CONFIRM_WORDS, true)) {
            Cache::forget($this->cacheKey());
            return $this->executePendingExpense($pending);
        }

        // Ambiguous reply while something's pending — don't guess, don't
        // silently drop the pending action, just ask again explicitly.
        return sprintf(
            "You still have a pending expense: RM%s at %s (%s). Reply yes to confirm or no to cancel.",
            number_format($pending['amount'], 2),
            $pending['store'],
            $pending['category']
        );
    }

    private function handleAddExpense(array $intent): string
    {
        if (! $intent['expense_store'] || ! $intent['expense_amount']) {
            return "To add an expense, tell me the store and amount — e.g. \"add RM50 food expense at KFC\".";
        }

        $category = $intent['category'] ?? 'Uncategorized';
        $date = $intent['expense_date'] ?? now()->toDateString();

        Cache::put($this->cacheKey(), [
            'store' => $intent['expense_store'],
            'amount' => $intent['expense_amount'],
            'category' => $category,
            'date' => $date,
        ], now()->addMinutes(10));

        return sprintf(
            "Add RM%s at %s under %s for %s? Reply yes to confirm or no to cancel.",
            number_format($intent['expense_amount'], 2),
            $intent['expense_store'],
            $category,
            $date === now()->toDateString() ? 'today' : $date
        );
    }

    /**
     * The ONLY place a chat-originated expense actually gets written to the
     * database — reached only after handlePendingAction() matched an
     * explicit confirm word against plain code, never an LLM decision.
     */
    private function executePendingExpense(array $pending): string
    {
        $receipt = \App\Models\Receipt::create([
            'user_id' => $this->userId,
            'store_name' => $pending['store'],
            'date' => $pending['date'],
            'total' => $pending['amount'],
            'total_myr' => $pending['amount'], // chat-added expenses are always entered in MYR
            'currency' => 'MYR',
            'category' => $pending['category'],
            'image_path' => null,
            'raw_extraction' => null,
            'confidence' => null,
        ]);

        return sprintf(
            "Added: RM%s at %s under %s. (id %d)",
            number_format($receipt->total_myr, 2),
            $receipt->store_name,
            $receipt->category,
            $receipt->id
        );
    }

    private function classifyIntent(string $message): array
    {
        $endpoint = config('services.chat_llm.url');
        $apiKey = config('services.chat_llm.key');

        try {
            $response = Http::withToken($apiKey)
                ->timeout(15)
                ->post($endpoint, [
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

        $month = $parsed['month'] ?? null;
        if ($month !== null && ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = null;
        }

        $expenseStore = $parsed['expense_store'] ?? null;
        $expenseAmount = isset($parsed['expense_amount']) ? (float) $parsed['expense_amount'] : null;
        $expenseDate = $parsed['expense_date'] ?? null;
        if ($expenseDate !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
            $expenseDate = null;
        }

        return [
            'intent' => $intent,
            'category' => $category,
            'month' => $month,
            'expense_store' => $expenseStore,
            'expense_amount' => $expenseAmount,
            'expense_date' => $expenseDate,
        ];
    }

    private function handleCategorySpend(array $intent, string $month): string
    {
        if (! $intent['category']) {
            return "Which category did you mean? For example: Groceries, Food & Drink, Transport, Health.";
        }

        $receipts = Receipt::where('user_id', $this->userId)
            ->inMonth($month)
            ->inCategory($intent['category'])
            ->get();

        if ($receipts->isEmpty()) {
            return "You have no {$intent['category']} receipts recorded for {$month}.";
        }

        $totalMyr = $receipts->sum('total_myr');
        $count = $receipts->count();

        $base = sprintf(
            "You've spent RM%s on %s this month, across %d receipt%s.",
            number_format($totalMyr, 2),
            $intent['category'],
            $count,
            $count === 1 ? '' : 's'
        );

        if ($count === 1 && $receipts->first()->currency !== 'MYR') {
            $original = $receipts->first();
            $base .= sprintf(
                ' (Originally %s%s.)',
                $original->currency,
                number_format((float) $original->total, 2)
            );
        }

        return $base;
    }

    private function handleTotalSpend(string $month): string
    {
        $total = Receipt::where('user_id', $this->userId)->inMonth($month)->sum('total_myr');

        return sprintf("You've spent RM%s in total this month.", number_format((float) $total, 2));
    }

    private function handleTopCategory(string $month): string
    {
        $row = Receipt::where('user_id', $this->userId)
            ->inMonth($month)
            ->selectRaw('category, SUM(total_myr) as total')
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

        $spending = (float) Receipt::where('user_id', $this->userId)->inMonth($month)->sum('total_myr');
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
            . '"how much did I spend on food this month", "what\'s my biggest category?", or "add RM50 food expense at KFC".';
    }

    private function handleRecommendation(array $intent, string $month): string
    {
        $spending = (float) Receipt::where('user_id', $this->userId)->inMonth($month)->sum('total_myr');
        $income = (float) (\App\Models\Income::where('user_id', $this->userId)->where('month', $month)->sum('amount'));

        $facts = [
            'month' => $month,
            'total_spending_myr' => round($spending, 2),
            'income_myr' => round($income, 2),
        ];

        if ($income > 0) {
            $facts['savings_rate_percent'] = round((($income - $spending) / $income) * 100);
        }

        if ($intent['category']) {
            $categorySpend = (float) \App\Models\Receipt::where('user_id', $this->userId)
                ->inMonth($month)->inCategory($intent['category'])->sum('total_myr');
            $facts['category'] = $intent['category'];
            $facts['category_spending_myr'] = round($categorySpend, 2);

            $goal = \App\Models\Goal::where('user_id', $this->userId)
                ->where('category', $intent['category'])->value('monthly_limit');
            if ($goal !== null) {
                $facts['category_budget_myr'] = (float) $goal;
            }
        } else {
            $byCategory = \App\Models\Receipt::where('user_id', $this->userId)
                ->inMonth($month)
                ->selectRaw('category, SUM(total_myr) as amount')
                ->groupBy('category')->orderByDesc('amount')->first();
            if ($byCategory) {
                $facts['top_category'] = $byCategory->category;
                $facts['top_category_spending_myr'] = round((float) $byCategory->amount, 2);
            }

            $goals = \App\Models\Goal::where('user_id', $this->userId)->get();
            if ($goals->isNotEmpty()) {
                $facts['budgets'] = $goals->map(fn ($g) => [
                    'category' => $g->category,
                    'monthly_limit_myr' => (float) $g->monthly_limit,
                ]);
            }
        }

        if ($spending === 0.0 && $income === 0.0) {
            return "Add a few receipts and set your income so I can give you a grounded recommendation.";
        }

        try {
            $response = Http::withToken(config('services.chat_llm.key'))
                ->timeout(15)
                ->post(str_replace('/chat-intent', '/chat-advise', config('services.chat_llm.url')), [
                    'facts' => $facts,
                ]);

            return $response->json('reply') ?? "Keep tracking consistently — that's the biggest factor in staying on budget.";
        } catch (\Throwable $e) {
            Log::warning('Advice generation failed', ['error' => $e->getMessage()]);
            return "Keep tracking consistently — that's the biggest factor in staying on budget.";
        }
    }
}