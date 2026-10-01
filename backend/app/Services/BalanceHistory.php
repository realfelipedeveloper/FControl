<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CardInstallment;
use App\Models\Transaction;
use App\Models\Transfer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BalanceHistory
{
    public function monthly(int $userId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $accountIds = Account::where('user_id', $userId)->pluck('id');
        $opening = Money::toCents((string) Account::whereIn('id', $accountIds)->sum('initial_balance'));
        $settled = Transaction::where('user_id', $userId)->whereIn('account_id', $accountIds)
            ->where(fn ($query) => $query->where(fn ($q) => $q->where('type', 'income')->where('status', 'received'))
                ->orWhere(fn ($q) => $q->where('type', 'expense')->where('status', 'paid')))
            ->selectRaw("DATE_FORMAT(COALESCE(settled_at, transaction_date), '%Y-%m-%d') as date, SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END) as total")
            ->groupBy('date')->toBase()->get();
        $incoming = Transfer::where('user_id', $userId)->whereIn('to_account_id', $accountIds)->whereNotIn('from_account_id', $accountIds)
            ->selectRaw('transferred_at as date, SUM(amount) as total')->groupBy('date')->toBase()->get();
        $outgoing = Transfer::where('user_id', $userId)->whereIn('from_account_id', $accountIds)->whereNotIn('to_account_id', $accountIds)
            ->selectRaw('transferred_at as date, -SUM(amount) as total')->groupBy('date')->toBase()->get();
        // Debt is recognized on purchase, not when each installment reaches its competence month.
        $debt = CardInstallment::query()->join('card_purchases', 'card_purchases.id', '=', 'card_installments.card_purchase_id')
            ->join('invoices', 'invoices.id', '=', 'card_installments.invoice_id')->where('card_purchases.user_id', $userId)
            ->selectRaw('card_purchases.purchased_at as purchased_at, invoices.paid_at as paid_at, SUM(card_installments.amount) as total')
            ->groupBy('card_purchases.purchased_at', 'invoices.paid_at')->toBase()->get();
        $movements = $settled->concat($incoming)->concat($outgoing);
        $series = collect();
        for ($month = $from->startOfMonth(); $month <= $to; $month = $month->addMonth()) {
            $cutoff = $month->endOfMonth()->min(CarbonImmutable::now())->toDateString();
            $cash = $opening + $movements->filter(fn ($row) => substr((string) $row->date, 0, 10) <= $cutoff)
                ->sum(fn ($row) => Money::toCents((string) $row->total));
            $liability = $debt->filter(fn ($row) => substr((string) $row->purchased_at, 0, 10) <= $cutoff && (! $row->paid_at || substr((string) $row->paid_at, 0, 10) > $cutoff))
                ->sum(fn ($row) => Money::toCents((string) $row->total));
            $series->push(['month' => $month->format('Y-m'), 'cash' => Money::fromCents($cash), 'total' => Money::fromCents($cash - $liability)]);
        }

        return $series;
    }
}
