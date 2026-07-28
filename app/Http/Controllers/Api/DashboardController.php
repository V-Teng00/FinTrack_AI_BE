<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SetIncomeRequest;
use App\Models\Income;
use App\Models\Receipt;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $month = $request->string('month')->toString() ?: now()->format('Y-m');
        $userId = $request->user()->id;

        $totalSpending = (float) Receipt::where('user_id', $userId)->inMonth($month)->sum('total_myr');

        $incomeSources = Income::where('user_id', $userId)->where('month', $month)->get();
        $income = (float) $incomeSources->sum('amount');
        $incomeUpdatedAt = $incomeSources->max('updated_at')?->toIso8601String();

        $byCategory = Receipt::where('user_id', $userId)
            ->inMonth($month)
            ->selectRaw('category, SUM(total_myr) as amount')
            ->groupBy('category')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => ['category' => $row->category, 'amount' => (float) $row->amount])
            ->values();

        $daily = Receipt::where('user_id', $userId)
            ->inMonth($month)
            ->selectRaw('date, SUM(total_myr) as amount')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => \Carbon\Carbon::parse($row->date)->format('m-d'),
                'amount' => (float) $row->amount,
            ])
            ->values();

        return response()->json([
            'total_spending' => $totalSpending,
            'income' => $income,
            'income_updated_at' => $incomeUpdatedAt,
            'income_sources' => $incomeSources->map(fn ($i) => [
                'id' => $i->id,
                'source' => $i->source,
                'amount' => (float) $i->amount,
                'notes' => $i->notes,
            ]),
            'savings' => $income - $totalSpending,
            'by_category' => $byCategory,
            'daily' => $daily,
        ]);
    }

    public function setIncome(SetIncomeRequest $request)
    {
        $income = $request->user()->incomes()->create([
            'month' => $request->month,
            'source' => $request->source,
            'amount' => $request->amount,
            'notes' => $request->notes,
        ]);

        return response()->json($income, 201);
    }

    public function deleteIncome(Request $request, Income $income)
    {
        abort_unless($income->user_id === $request->user()->id, 403);
        $income->delete();

        return response()->json(null, 204);
    }

    public function incomeHistory(Request $request)
    {
        $incomes = Income::where('user_id', $request->user()->id)
            ->orderByDesc('month')
            ->orderByDesc('created_at')
            ->get(['id', 'month', 'source', 'amount', 'notes', 'updated_at']);

        return response()->json(['data' => $incomes]);
    }
}