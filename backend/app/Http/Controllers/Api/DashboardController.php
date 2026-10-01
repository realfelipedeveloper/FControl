<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\CardInstallment;
use App\Models\CreditCard;
use App\Models\Goal;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $uid = $request->user()->id;
        $start = CarbonImmutable::now()->startOfMonth();
        $end = $start->endOfMonth();
        $base = Transaction::where('user_id', $uid)->where('affects_metrics', true)->whereBetween('competence_date', [$start, $end])->whereNot('status', 'cancelled');
        $income = (string) (clone $base)->where('type', 'income')->sum('amount');
        $transactionExpense = (string) (clone $base)->where('type', 'expense')->sum('amount');
        $cardExpense = (string) CardInstallment::whereHas('purchase', fn ($query) => $query->where('user_id', $uid))->whereBetween('competence_date', [$start, $end])->sum('amount');
        $expense = $this->add($transactionExpense, $cardExpense);
        $accounts = Account::where('user_id', $uid)->where('active', true)->get()->each->append('current_balance');
        $balance = $accounts->reduce(fn (string $total, Account $account) => $this->add($total, $account->current_balance), '0.00');
        $pendingIncome = (string) (clone $base)->where('type', 'income')->whereIn('status', ['planned', 'pending', 'overdue'])->sum('amount');
        $pendingExpense = (string) (clone $base)->where('type', 'expense')->whereIn('status', ['planned', 'pending', 'overdue'])->sum('amount');
        $openInvoices = (string) Invoice::where('user_id', $uid)->whereIn('status', ['open', 'closed', 'overdue'])->sum('total');
        $projected = $this->subtract($this->add($balance, $pendingIncome), $this->add($pendingExpense, $openInvoices));
        $monthly = $this->monthlySeries($uid, $start->subMonths(11), $end);
        $categories = $this->categorySeries($uid, $start, $end);
        $accountSpending = $this->accountSpending($uid, $start, $end);
        $cardSpending = $this->cardSpending($uid, $start, $end);
        $budgets = Budget::where('user_id', $uid)->whereDate('month', $start)->with('category')->get()->map(function (Budget $budget) use ($base, $uid, $start, $end) {
            $normal = (string) (clone $base)->where('type', 'expense')->where('category_id', $budget->category_id)->sum('amount');
            $card = (string) CardInstallment::whereHas('purchase', fn ($query) => $query->where('user_id', $uid)->where('category_id', $budget->category_id))->whereBetween('competence_date', [$start, $end])->sum('amount');
            $realized = $this->add($normal, $card);
            $remaining = $this->subtract((string) $budget->planned_amount, $realized);
            $percentage = Money::toCents((string) $budget->planned_amount) > 0 ? round(Money::toCents($realized) / Money::toCents((string) $budget->planned_amount) * 100, 2) : 0;

            return [...$budget->toArray(), 'realized_amount' => $realized, 'remaining_amount' => $remaining, 'percentage' => $percentage, 'indicator' => $percentage > 100 ? 'exceeded' : ($percentage >= 80 ? 'near' : 'normal')];
        });
        $result = $this->subtract($income, $expense);
        $overdueInvoices = (string) Invoice::where('user_id', $uid)->where('status', 'overdue')->sum('total');
        $netWorth = $this->subtract($balance, $openInvoices);

        return response()->json(['data' => [
            'summary' => [
                'current_balance' => $balance,
                'projected_balance' => $projected,
                'projected_month_closing' => $projected,
                'net_worth' => $netWorth,
                'monthly_income' => $income,
                'monthly_expense' => $expense,
                'monthly_result' => $result,
                'savings_rate' => Money::toCents($income) > 0 ? round(Money::toCents($result) / Money::toCents($income) * 100, 2) : 0,
                'average_income' => $this->average($monthly, 'income'),
                'average_expense' => $this->average($monthly, 'expense'),
                'payable' => $this->add($pendingExpense, $openInvoices),
                'receivable' => $pendingIncome,
                'overdue' => $this->add((string) (clone $base)->where('status', 'overdue')->sum('amount'), $overdueInvoices),
                'fixed_expenses' => (string) (clone $base)->where('type', 'expense')->where('is_fixed', true)->sum('amount'),
                'variable_expenses' => $this->add((string) (clone $base)->where('type', 'expense')->where('is_fixed', false)->sum('amount'), $cardExpense),
            ],
            'accounts' => $accounts,
            'monthly' => $monthly,
            'net_worth_evolution' => $this->netWorthEvolution($monthly, $netWorth),
            'categories' => $categories,
            'account_spending' => $accountSpending,
            'card_spending' => $cardSpending,
            'largest_expenses' => $this->largestExpenses($uid, $start, $end),
            'upcoming' => Transaction::where('user_id', $uid)->whereIn('status', ['pending', 'overdue'])->whereNotNull('due_date')->orderBy('due_date')->limit(8)->get(),
            'overdue' => Transaction::where('user_id', $uid)->where('status', 'overdue')->orderBy('due_date')->limit(8)->get(),
            'budgets' => $budgets,
            'goals' => Goal::where('user_id', $uid)->where('status', 'active')->limit(6)->get(),
            'cards' => CreditCard::where('user_id', $uid)->where('active', true)->with(['invoices' => fn ($query) => $query->whereIn('status', ['open', 'closed', 'overdue'])->orderBy('due_date')])->get(),
            'recent' => Transaction::where('user_id', $uid)->with(['account', 'category'])->latest('transaction_date')->limit(10)->get(),
        ]]);
    }

    private function monthlySeries(int $uid, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $transactions = DB::table('transactions')->where('user_id', $uid)->where('affects_metrics', true)->whereBetween('competence_date', [$from, $to])->where('status', '!=', 'cancelled')->whereNull('deleted_at')->selectRaw("DATE_FORMAT(competence_date, '%Y-%m') as month, type, SUM(amount) as total")->groupBy('month', 'type')->get();
        $cards = DB::table('card_installments')->join('card_purchases', 'card_purchases.id', '=', 'card_installments.card_purchase_id')->where('card_purchases.user_id', $uid)->whereBetween('competence_date', [$from, $to])->selectRaw("DATE_FORMAT(competence_date, '%Y-%m') as month, 'expense' as type, SUM(amount) as total")->groupBy('month')->get();
        $totals = $transactions->concat($cards)->groupBy(fn ($row) => $row->month.'|'.$row->type)->map(function (Collection $rows) {
            $first = $rows->first();

            return $rows->reduce(fn (string $sum, $row) => $this->add($sum, (string) $row->total), '0.00');
        });
        $series = collect();
        for ($month = $from->startOfMonth(); $month <= $to; $month = $month->addMonth()) {
            foreach (['income', 'expense'] as $type) {
                $key = $month->format('Y-m').'|'.$type;
                $series->push(['month' => $month->format('Y-m'), 'type' => $type, 'total' => $totals->get($key, '0.00')]);
            }
        }

        return $series;
    }

    private function categorySeries(int $uid, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $transactions = DB::table('transactions')->where('transactions.user_id', $uid)->where('affects_metrics', true)->where('transactions.type', 'expense')->whereBetween('competence_date', [$start, $end])->where('status', '!=', 'cancelled')->whereNull('transactions.deleted_at')->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')->selectRaw("COALESCE(categories.name, 'Sem categoria') as name, SUM(amount) as total")->groupBy('categories.name')->get();
        $cards = DB::table('card_installments')->join('card_purchases', 'card_purchases.id', '=', 'card_installments.card_purchase_id')->where('card_purchases.user_id', $uid)->whereBetween('competence_date', [$start, $end])->leftJoin('categories', 'categories.id', '=', 'card_purchases.category_id')->selectRaw("COALESCE(categories.name, 'Sem categoria') as name, SUM(amount) as total")->groupBy('categories.name')->get();

        return $transactions->concat($cards)->groupBy('name')->map(fn (Collection $rows, string $name) => ['name' => $name, 'total' => $rows->reduce(fn (string $sum, $row) => $this->add($sum, (string) $row->total), '0.00')])->sortByDesc('total')->values();
    }

    private function average(Collection $monthly, string $type): string
    {
        $rows = $monthly->where('type', $type);
        if ($rows->isEmpty()) {
            return '0.00';
        }
        $total = $rows->reduce(fn (string $sum, array $row) => $this->add($sum, $row['total']), '0.00');

        return Money::fromCents(intdiv(Money::toCents($total), $rows->count()));
    }

    private function accountSpending(int $uid, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return DB::table('transactions')->join('accounts', 'accounts.id', '=', 'transactions.account_id')->where('transactions.user_id', $uid)->where('transactions.affects_metrics', true)->where('transactions.type', 'expense')->whereBetween('transactions.competence_date', [$start, $end])->where('transactions.status', '!=', 'cancelled')->whereNull('transactions.deleted_at')->selectRaw('accounts.name, SUM(transactions.amount) as total')->groupBy('accounts.id', 'accounts.name')->orderByDesc('total')->get();
    }

    private function cardSpending(int $uid, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return DB::table('card_installments')->join('card_purchases', 'card_purchases.id', '=', 'card_installments.card_purchase_id')->join('credit_cards', 'credit_cards.id', '=', 'card_purchases.credit_card_id')->where('card_purchases.user_id', $uid)->whereBetween('card_installments.competence_date', [$start, $end])->selectRaw('credit_cards.name, SUM(card_installments.amount) as total')->groupBy('credit_cards.id', 'credit_cards.name')->orderByDesc('total')->get();
    }

    private function largestExpenses(int $uid, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $transactions = DB::table('transactions')->where('user_id', $uid)->where('affects_metrics', true)->where('type', 'expense')->whereBetween('competence_date', [$start, $end])->where('status', '!=', 'cancelled')->whereNull('deleted_at')->selectRaw("CONCAT('transaction-', id) as row_id, description, amount, competence_date, 'transaction' as source");
        $cards = DB::table('card_installments')->join('card_purchases', 'card_purchases.id', '=', 'card_installments.card_purchase_id')->where('card_purchases.user_id', $uid)->whereBetween('card_installments.competence_date', [$start, $end])->selectRaw("CONCAT('card-', card_installments.id) as row_id, card_purchases.description, card_installments.amount, card_installments.competence_date, 'card' as source");

        return DB::query()->fromSub($transactions->unionAll($cards), 'expenses')->orderByDesc('amount')->limit(5)->get();
    }

    private function netWorthEvolution(Collection $monthly, string $currentNetWorth): Collection
    {
        $grouped = $monthly->groupBy('month');
        $periodResult = $grouped->reduce(function (string $sum, Collection $rows) {
            $income = (string) ($rows->firstWhere('type', 'income')['total'] ?? '0.00');
            $expense = (string) ($rows->firstWhere('type', 'expense')['total'] ?? '0.00');

            return $this->add($sum, $this->subtract($income, $expense));
        }, '0.00');
        $running = $this->subtract($currentNetWorth, $periodResult);

        return $grouped->map(function (Collection $rows, string $month) use (&$running) {
            $income = (string) ($rows->firstWhere('type', 'income')['total'] ?? '0.00');
            $expense = (string) ($rows->firstWhere('type', 'expense')['total'] ?? '0.00');
            $running = $this->add($running, $this->subtract($income, $expense));

            return ['month' => $month, 'total' => $running];
        })->values();
    }

    private function add(string $left, string $right): string
    {
        return Money::fromCents(Money::toCents($left) + Money::toCents($right));
    }

    private function subtract(string $left, string $right): string
    {
        return Money::fromCents(Money::toCents($left) - Money::toCents($right));
    }
}
