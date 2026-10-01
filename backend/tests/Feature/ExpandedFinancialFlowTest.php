<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Invoice;
use App\Models\Recurrence;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpandedFinancialFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('d', 64)]);
        Carbon::setTestNow('2026-09-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function parcela_entra_na_competencia_e_pagamento_da_fatura_afeta_apenas_o_caixa(): void
    {
        [$user, $account, $card, $token] = $this->financialContext();
        $this->withToken($token)->postJson('/api/v1/card-purchases', ['credit_card_id' => $card->id, 'description' => 'Curso', 'total_amount' => '100.00', 'installment_count' => 1, 'purchased_at' => '2026-09-01'])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.summary.monthly_expense', '100.00')
            ->assertJsonPath('data.summary.variable_expenses', '100.00')
            ->assertJsonPath('data.card_spending.0.total', '100.00')
            ->assertJsonPath('data.largest_expenses.0.description', 'Curso');
        $this->withToken($token)->getJson('/api/v1/cards')->assertOk()->assertJsonPath('data.0.used_limit', '100.00')->assertJsonPath('data.0.available_limit', '900.00');
        $invoice = Invoice::where('user_id', $user->id)->firstOrFail();
        $this->withToken($token)->postJson("/api/v1/invoices/{$invoice->id}/pay")->assertOk();

        self::assertSame('400.00', $account->fresh()->current_balance);
        $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.summary.monthly_expense', '100.00');
    }

    #[Test]
    public function relatorio_unifica_lancamentos_e_parcelas_e_filtra_por_cartao(): void
    {
        [, $account, $card, $token] = $this->financialContext();
        $this->withToken($token)->postJson('/api/v1/transactions', ['account_id' => $account->id, 'type' => 'income', 'description' => 'Salário', 'amount' => '500.00', 'transaction_date' => '2026-09-01', 'competence_date' => '2026-09-01', 'status' => 'received'])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/card-purchases', ['credit_card_id' => $card->id, 'description' => 'Curso', 'total_amount' => '100.00', 'installment_count' => 1, 'purchased_at' => '2026-09-01'])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/reports')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson("/api/v1/reports?credit_card_id={$card->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'card');
    }

    #[Test]
    public function recorrencia_nao_aceita_conta_de_outro_usuario(): void
    {
        [$owner, $attacker] = User::factory()->count(2)->create();
        $account = Account::create(['user_id' => $owner->id, 'name' => 'Privada', 'type' => 'checking', 'initial_balance' => '0.00']);
        $token = auth('api')->login($attacker);

        $this->withToken($token)->postJson('/api/v1/recurrences', ['frequency' => 'monthly', 'start_date' => '2026-09-01', 'next_execution_at' => '2026-09-01', 'active' => true, 'template' => ['account_id' => $account->id, 'type' => 'expense', 'description' => 'Indevida', 'amount' => '10.00', 'status' => 'pending']])->assertUnprocessable()->assertJsonValidationErrors('template.account_id');
    }

    #[Test]
    public function usuario_lista_e_revoga_uma_familia_de_sessao(): void
    {
        $user = User::factory()->create(['password' => 'SenhaForte123']);
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'SenhaForte123', 'device_name' => 'Teste automatizado']);
        $token = $login->json('data.access_token');
        $sessionId = $this->withToken($token)->getJson('/api/v1/auth/sessions')->assertOk()->json('data.0.id');

        $this->withToken($token)->deleteJson("/api/v1/auth/sessions/$sessionId")->assertOk();
        $this->assertDatabaseMissing('refresh_tokens', ['id' => $sessionId, 'revoked_at' => null]);
    }

    #[Test]
    public function categoria_rejeita_tipo_incompativel_e_ciclo_na_hierarquia(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);
        $expense = Category::create(['user_id' => $user->id, 'name' => 'Moradia', 'type' => 'expense']);

        $this->withToken($token)->postJson('/api/v1/categories', ['parent_id' => $expense->id, 'name' => 'Salário', 'type' => 'income'])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $child = Category::create(['user_id' => $user->id, 'parent_id' => $expense->id, 'name' => 'Aluguel', 'type' => 'expense']);
        $this->withToken($token)->patchJson("/api/v1/categories/{$expense->id}", ['parent_id' => $child->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    #[Test]
    public function listagens_ignoram_colunas_incompativeis_e_limitam_paginacao(): void
    {
        [, , , $token] = $this->financialContext();
        foreach (['accounts', 'categories', 'tags', 'transactions', 'cards', 'budgets', 'goals', 'recurrences'] as $resource) {
            $this->withToken($token)->getJson("/api/v1/$resource?sort=name&search=teste&credit_card_id=1&per_page=0")
                ->assertOk()->assertJsonPath('per_page', 1);
        }
        $this->withToken($token)->postJson('/api/v1/budgets', ['month' => '2026-09-01', 'planned_amount' => '10.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    #[Test]
    public function recorrencia_rejeita_campos_internos_e_substituicao_incompleta_do_template(): void
    {
        [, $account, , $token] = $this->financialContext();
        $template = ['account_id' => $account->id, 'type' => 'expense', 'description' => 'Aluguel', 'amount' => '10.00', 'status' => 'pending'];
        $body = ['frequency' => 'monthly', 'start_date' => '2026-09-01', 'next_execution_at' => '2026-09-01', 'active' => true, 'template' => $template];
        $this->withToken($token)->postJson('/api/v1/recurrences', [...$body, 'template' => [...$template, 'affects_metrics' => false]])
            ->assertUnprocessable()->assertJsonValidationErrors('template');
        $id = $this->withToken($token)->postJson('/api/v1/recurrences', $body)->assertCreated()->json('data.id');
        $this->withToken($token)->patchJson("/api/v1/recurrences/$id", ['template' => ['amount' => '20.00']])
            ->assertUnprocessable()->assertJsonValidationErrors('template.account_id');
        $this->withToken($token)->patchJson("/api/v1/recurrences/$id", ['active' => false])->assertOk();
    }

    #[Test]
    public function recorrencia_respeita_limites_e_preserva_dia_ancora_apos_fevereiro(): void
    {
        [$user, $account] = $this->financialContext();
        $recurrence = Recurrence::create(['user_id' => $user->id, 'frequency' => 'monthly', 'start_date' => '2026-01-31', 'next_execution_at' => '2026-01-31', 'max_occurrences' => 2, 'active' => true,
            'template' => ['account_id' => $account->id, 'type' => 'expense', 'description' => 'Mensalidade', 'amount' => '10.00', 'status' => 'pending']]);
        $this->artisan('fcontrol:materialize-recurrences')->assertSuccessful();
        self::assertSame('2026-02-28', $recurrence->fresh()->next_execution_at->toDateString());
        $this->artisan('fcontrol:materialize-recurrences')->assertSuccessful();
        self::assertSame('2026-03-31', $recurrence->fresh()->next_execution_at->toDateString());
        self::assertFalse($recurrence->fresh()->active);
        $this->artisan('fcontrol:materialize-recurrences')->assertSuccessful();
        self::assertSame(2, Transaction::where('recurrence_id', $recurrence->id)->count());
    }

    #[Test]
    public function recorrencia_expirada_nao_materializa_lancamento(): void
    {
        [$user, $account] = $this->financialContext();
        Recurrence::create(['user_id' => $user->id, 'frequency' => 'monthly', 'start_date' => '2026-01-01', 'end_date' => '2026-02-01', 'next_execution_at' => '2026-03-01', 'active' => true,
            'template' => ['account_id' => $account->id, 'type' => 'expense', 'description' => 'Encerrada', 'amount' => '10.00', 'status' => 'pending']]);
        $this->artisan('fcontrol:materialize-recurrences')->assertSuccessful();
        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function projecao_inclui_atrasados_mas_nao_faturas_futuras(): void
    {
        [, $account, $card, $token] = $this->financialContext();
        $this->withToken($token)->postJson('/api/v1/transactions', ['account_id' => $account->id, 'type' => 'expense', 'description' => 'Conta atrasada', 'amount' => '50.00', 'transaction_date' => '2026-08-01', 'competence_date' => '2026-08-01', 'due_date' => '2026-08-10', 'status' => 'pending'])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/card-purchases', ['credit_card_id' => $card->id, 'description' => 'Compra parcelada', 'total_amount' => '300.00', 'installment_count' => 3, 'purchased_at' => '2026-09-01'])->assertCreated();
        $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.summary.projected_month_closing', '350.00')
            ->assertJsonPath('data.summary.payable', '350.00')
            ->assertJsonPath('data.summary.overdue', '50.00')
            ->assertJsonPath('data.summary.current_balance', '500.00')
            ->assertJsonPath('data.summary.net_worth', '200.00');
    }

    #[Test]
    public function historico_separa_competencia_recebimento_e_divida_integral_do_cartao(): void
    {
        [$user, $account, $card, $token] = $this->financialContext();
        $this->withToken($token)->postJson('/api/v1/transactions', ['account_id' => $account->id, 'type' => 'income', 'description' => 'Recebimento tardio', 'amount' => '200.00', 'transaction_date' => '2026-08-01', 'competence_date' => '2026-08-01', 'settled_at' => '2026-09-02', 'status' => 'received'])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/card-purchases', ['credit_card_id' => $card->id, 'description' => 'Parcelada', 'total_amount' => '300.00', 'installment_count' => 3, 'purchased_at' => '2026-08-01'])->assertCreated();
        $invoice = Invoice::where('user_id', $user->id)->orderBy('due_date')->firstOrFail();
        $this->withToken($token)->postJson("/api/v1/invoices/{$invoice->id}/pay")->assertOk();
        $response = $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk();
        $history = collect($response->json('data.net_worth_evolution'))->keyBy('month');
        self::assertSame('500.00', $history['2026-08']['cash']);
        self::assertSame('200.00', $history['2026-08']['total']);
        self::assertSame('600.00', $history['2026-09']['cash']);
        self::assertSame('400.00', $history['2026-09']['total']);
        $response->assertJsonPath('data.summary.net_worth', '400.00');
    }

    #[Test]
    public function orcamento_normaliza_mes_e_lancamento_rejeita_status_incompativel(): void
    {
        [$user, $account, , $token] = $this->financialContext();
        $category = Category::create(['user_id' => $user->id, 'name' => 'Moradia', 'type' => 'expense']);
        $this->withToken($token)->postJson('/api/v1/budgets', ['category_id' => $category->id, 'month' => '2026-09-15', 'planned_amount' => '100.00'])->assertCreated();
        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'month' => '2026-09-01']);
        $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk()->assertJsonCount(1, 'data.budgets');
        $this->withToken($token)->postJson('/api/v1/transactions', ['account_id' => $account->id, 'type' => 'expense', 'description' => 'Inválida', 'amount' => '10.00', 'transaction_date' => '2026-09-01', 'competence_date' => '2026-09-01', 'status' => 'received'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    #[Test]
    public function contribuicoes_preservam_total_e_impedem_alteracao_avulsa_do_saldo(): void
    {
        [, , , $token] = $this->financialContext();
        $id = $this->withToken($token)->postJson('/api/v1/goals', ['name' => 'Reserva', 'target_amount' => '100.00', 'current_amount' => '10.00', 'status' => 'active'])->assertCreated()->json('data.id');
        foreach (['20.00', '30.00'] as $amount) {
            $this->withToken($token)->postJson("/api/v1/goals/$id/contributions", ['amount' => $amount, 'contributed_at' => '2026-09-15'])->assertCreated();
        }
        $this->withToken($token)->getJson("/api/v1/goals/$id")->assertOk()->assertJsonPath('data.current_amount', '60.00')->assertJsonCount(2, 'data.contributions');
        $this->withToken($token)->patchJson("/api/v1/goals/$id", ['current_amount' => '1.00'])->assertUnprocessable()->assertJsonValidationErrors('current_amount');
        $this->withToken($token)->patchJson("/api/v1/goals/$id", ['name' => 'Reserva atualizada', 'current_amount' => 60])->assertOk();
    }

    private function financialContext(): array
    {
        $user = User::factory()->create();
        $account = Account::create(['user_id' => $user->id, 'name' => 'Conta', 'type' => 'checking', 'initial_balance' => '500.00']);
        $card = CreditCard::create(['user_id' => $user->id, 'payment_account_id' => $account->id, 'name' => 'Cartão', 'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 17]);

        return [$user, $account, $card, auth('api')->login($user)];
    }
}
