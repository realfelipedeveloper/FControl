<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->query($request)->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function csv(Request $request): StreamedResponse
    {
        $query = $this->query($request);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Data', 'Competência', 'Tipo', 'Descrição', 'Conta', 'Cartão', 'Categoria', 'Status', 'Valor'], ';');
            $query->chunk(500, function ($items) use ($out) {
                foreach ($items as $item) {
                    fputcsv($out, [$item->transaction_date, $item->competence_date, $item->type, $item->description, $item->account_name, $item->credit_card_name, $item->category_name, $item->status, $item->amount], ';');
                }
            });
            fclose($out);
        }, 'relatorio-fcontrol.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function query(Request $request): Builder
    {
        $uid = $request->user()->id;
        $transactions = DB::table('transactions')
            ->leftJoin('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $uid)
            ->whereNull('transactions.deleted_at')
            ->where('transactions.affects_metrics', true)
            ->selectRaw("CONCAT('transaction-', transactions.id) as row_id, transactions.id, 'transaction' as source, transactions.transaction_date, transactions.competence_date, transactions.due_date, transactions.type, transactions.description, transactions.amount, transactions.status, transactions.account_id, accounts.name as account_name, transactions.category_id, categories.name as category_name, NULL as credit_card_id, NULL as credit_card_name");

        $cards = DB::table('card_installments')
            ->join('card_purchases', 'card_purchases.id', '=', 'card_installments.card_purchase_id')
            ->join('invoices', 'invoices.id', '=', 'card_installments.invoice_id')
            ->join('credit_cards', 'credit_cards.id', '=', 'card_purchases.credit_card_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'credit_cards.payment_account_id')
            ->leftJoin('categories', 'categories.id', '=', 'card_purchases.category_id')
            ->where('card_purchases.user_id', $uid)
            ->selectRaw("CONCAT('card-', card_installments.id) as row_id, card_installments.id, 'card' as source, card_purchases.purchased_at as transaction_date, card_installments.competence_date, invoices.due_date, 'expense' as type, card_purchases.description, card_installments.amount, CASE WHEN invoices.status = 'paid' THEN 'paid' WHEN invoices.status = 'overdue' THEN 'overdue' ELSE 'pending' END as status, credit_cards.payment_account_id as account_id, accounts.name as account_name, card_purchases.category_id, categories.name as category_name, credit_cards.id as credit_card_id, credit_cards.name as credit_card_name");

        if ($request->filled('tag_id')) {
            $tagId = $request->integer('tag_id');
            $transactions->whereExists(fn ($query) => $query->selectRaw('1')->from('tag_transaction')->whereColumn('tag_transaction.transaction_id', 'transactions.id')->where('tag_transaction.tag_id', $tagId));
            $cards->whereRaw('1 = 0');
        }

        $query = DB::query()->fromSub($transactions->unionAll($cards), 'report_rows');
        foreach (['account_id', 'category_id', 'credit_card_id', 'type', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('from')) {
            $query->whereDate('transaction_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('transaction_date', '<=', $request->input('to'));
        }
        if ($request->filled('min_amount')) {
            $query->where('amount', '>=', $request->input('min_amount'));
        }
        if ($request->filled('max_amount')) {
            $query->where('amount', '<=', $request->input('max_amount'));
        }
        if ($request->filled('search')) {
            $query->where('description', 'like', '%'.$request->input('search').'%');
        }
        $sort = in_array($request->string('sort')->toString(), ['transaction_date', 'competence_date', 'due_date', 'description', 'amount', 'status'], true) ? $request->string('sort')->toString() : 'transaction_date';
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->orderBy('row_id');
    }
}
