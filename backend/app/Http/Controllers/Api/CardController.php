<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CardPurchase;
use App\Models\CreditCard;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\AuditService;
use App\Services\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CardController extends Controller
{
    public function purchases(Request $request): JsonResponse
    {
        $query = CardPurchase::where('user_id', $request->user()->id)->with(['creditCard', 'category', 'installments.invoice']);
        foreach (['credit_card_id', 'category_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('from')) {
            $query->whereDate('purchased_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('purchased_at', '<=', $request->input('to'));
        }

        return response()->json($query->latest('purchased_at')->paginate(min($request->integer('per_page', 15), 100)));
    }

    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate(['credit_card_id' => ['required', Rule::exists('credit_cards', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at')], 'category_id' => ['nullable', Rule::exists('categories', 'id')->where('user_id', $request->user()->id)->whereNull('deleted_at')], 'description' => ['required', 'string', 'max:180'], 'total_amount' => ['required', 'decimal:0,2', 'gt:0'], 'installment_count' => ['required', 'integer', 'between:1,120'], 'purchased_at' => ['required', 'date']]);
        $purchase = DB::transaction(function () use ($request, $data) {
            $card = CreditCard::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($data['credit_card_id']);
            $purchase = CardPurchase::create([...$data, 'user_id' => $request->user()->id]);
            $parts = Money::split(Money::toCents((string) $data['total_amount']), (int) $data['installment_count']);
            $date = CarbonImmutable::parse($data['purchased_at']);
            for ($i = 0; $i < count($parts); $i++) {
                $competence = $date->addMonthsNoOverflow($i);
                if ((int) $date->day > $card->closing_day) {
                    $competence = $competence->addMonthNoOverflow();
                }
                $start = $competence->startOfMonth();
                $end = $competence->endOfMonth();
                $due = $competence->day(min($card->due_day, $competence->daysInMonth));
                $invoice = Invoice::firstOrCreate(['credit_card_id' => $card->id, 'period_start' => $start->toDateString()], ['user_id' => $request->user()->id, 'period_end' => $end->toDateString(), 'due_date' => $due->toDateString(), 'status' => 'open', 'total' => '0.00']);
                $purchase->installments()->create(['invoice_id' => $invoice->id, 'number' => $i + 1, 'amount' => Money::fromCents($parts[$i]), 'competence_date' => $competence->toDateString()]);
                $invoice->update(['total' => Money::fromCents(Money::toCents((string) $invoice->total) + $parts[$i])]);
            }

            return $purchase;
        });
        AuditService::record($request, 'card_purchase.created', $purchase);

        return response()->json(['data' => $purchase->load('installments.invoice'), 'message' => 'Compra e parcelas criadas.'], 201);
    }

    public function invoices(Request $request): JsonResponse
    {
        Invoice::where('user_id', $request->user()->id)->whereIn('status', ['open', 'closed'])->whereDate('due_date', '<', today())->update(['status' => 'overdue']);

        $query = Invoice::where('user_id', $request->user()->id)->with(['creditCard', 'installments.purchase']);
        foreach (['credit_card_id', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        return response()->json($query->latest('due_date')->paginate(min($request->integer('per_page', 15), 100)));
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::where('user_id', $request->user()->id)->with('creditCard')->findOrFail($id);
        abort_if($invoice->status === 'paid', 422, 'Fatura já paga.');
        DB::transaction(function () use ($invoice, $request) {
            $invoice->lockForUpdate();
            Transaction::create(['user_id' => $request->user()->id, 'account_id' => $invoice->creditCard->payment_account_id, 'type' => 'expense', 'description' => 'Pagamento de fatura '.$invoice->creditCard->name, 'amount' => $invoice->total, 'transaction_date' => now()->toDateString(), 'competence_date' => $invoice->period_start, 'settled_at' => now()->toDateString(), 'status' => 'paid', 'affects_metrics' => false, 'notes' => 'Pagamento financeiro da fatura; excluído das métricas de competência.']);
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
        });
        AuditService::record($request, 'invoice.paid', $invoice);

        return response()->json(['data' => $invoice->fresh(), 'message' => 'Fatura paga.']);
    }
}
