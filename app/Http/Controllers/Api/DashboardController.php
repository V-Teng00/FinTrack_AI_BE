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

        $income = (float) (Income::where('user_id', $userId)->where('month', $month)->value('amount') ?? 0);

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
            'savings' => $income - $totalSpending,
            'by_category' => $byCategory,
            'daily' => $daily,
        ]);
    }

    public function setIncome(SetIncomeRequest $request)
    {
        $income = Income::updateOrCreate(
            ['user_id' => $request->user()->id, 'month' => $request->month],
            ['amount' => $request->amount],
        );

        return response()->json($income);
    }
}