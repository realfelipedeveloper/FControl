<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\CreditCard;
use App\Models\Goal;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $uid = $request->user()->id;
        $start = CarbonImmutable::now()->startOfMonth();
        $end = $start->endOfMonth();
        $base = Transaction::where('user_id', $uid)->whereBetween('competence_date', [$start, $end])->whereNot('status', 'cancelled');
        $income = (clone $base)->where('type', 'income')->sum('amount');
        $expense = (clone $base)->where('type', 'expense')->sum('amount');
        $accounts = Account::where('user_id', $uid)->where('active', true)->get()->each->append('current_balance');
        $balance = $accounts->sum(fn ($a) => (float) $a->getAttribute('current_balance'));
        $monthly = Transaction::where('user_id', $uid)->where('competence_date', '>=', $start->subMonths(11))->whereNot('status', 'cancelled')->selectRaw("DATE_FORMAT(competence_date, '%Y-%m') as month, type, SUM(amount) as total")->groupBy('month', 'type')->orderBy('month')->get();
        $categories = Transaction::where('transactions.user_id', $uid)->where('transactions.type', 'expense')->whereBetween('competence_date', [$start, $end])->whereNot('status', 'cancelled')->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')->selectRaw("COALESCE(categories.name, 'Sem categoria') as name, SUM(amount) as total")->groupBy('categories.name')->orderByDesc('total')->get();

        return response()->json(['data' => ['summary' => ['current_balance' => number_format($balance, 2, '.', ''), 'projected_balance' => number_format($balance + (float) $income - (float) $expense, 2, '.', ''), 'monthly_income' => (string) $income, 'monthly_expense' => (string) $expense, 'monthly_result' => number_format((float) $income - (float) $expense, 2, '.', ''), 'savings_rate' => (float) $income > 0 ? round((((float) $income - (float) $expense) / (float) $income) * 100, 2) : 0, 'payable' => (clone $base)->where('type', 'expense')->whereIn('status', ['pending', 'overdue'])->sum('amount'), 'receivable' => (clone $base)->where('type', 'income')->whereIn('status', ['pending', 'overdue'])->sum('amount')], 'accounts' => $accounts, 'monthly' => $monthly, 'categories' => $categories, 'largest_expenses' => (clone $base)->where('type', 'expense')->orderByDesc('amount')->limit(5)->get(), 'upcoming' => Transaction::where('user_id', $uid)->whereIn('status', ['pending', 'overdue'])->whereNotNull('due_date')->orderBy('due_date')->limit(8)->get(), 'budgets' => Budget::where('user_id', $uid)->whereDate('month', $start)->with('category')->get(), 'goals' => Goal::where('user_id', $uid)->where('status', 'active')->limit(6)->get(), 'cards' => CreditCard::where('user_id', $uid)->where('active', true)->with(['invoices' => fn ($q) => $q->whereIn('status', ['open', 'closed', 'overdue'])->orderBy('due_date')])->get(), 'recent' => Transaction::where('user_id', $uid)->with(['account', 'category'])->latest('transaction_date')->limit(10)->get()]]);
    }
}
