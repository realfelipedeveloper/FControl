<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($this->query($request)->with(['account', 'category', 'tags'])->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function csv(Request $request): StreamedResponse
    {
        $query = $this->query($request)->with(['account', 'category']);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Data', 'Tipo', 'Descrição', 'Conta', 'Categoria', 'Status', 'Valor'], ';');
            $query->orderBy('transaction_date')->chunk(500, function ($items) use ($out) {
                foreach ($items as $i) {
                    /** @var Transaction $i */
                    fputcsv($out, [$i->transaction_date->format('d/m/Y'), $i->type, $i->description, $i->account->name, $i->category?->name, $i->status, $i->amount], ';');
                }
            });
            fclose($out);
        }, 'relatorio-fcontrol.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function query(Request $r): Builder
    {
        $q = Transaction::where('user_id', $r->user()->id);
        foreach (['account_id', 'category_id', 'type', 'status'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->input($f));
            }
        }if ($r->filled('from')) {
            $q->whereDate('transaction_date', '>=', $r->input('from'));
        }if ($r->filled('to')) {
            $q->whereDate('transaction_date', '<=', $r->input('to'));
        }if ($r->filled('min_amount')) {
            $q->where('amount', '>=', $r->input('min_amount'));
        }if ($r->filled('max_amount')) {
            $q->where('amount', '<=', $r->input('max_amount'));
        }if ($r->filled('search')) {
            $q->where('description', 'like', '%'.$r->input('search').'%');
        }if ($r->filled('tag_id')) {
            $q->whereHas('tags', fn ($t) => $t->where('tags.id', $r->input('tag_id')));
        }

        return $q->latest('transaction_date');
    }
}
