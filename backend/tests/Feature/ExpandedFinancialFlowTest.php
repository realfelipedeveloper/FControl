<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Invoice;
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

    private function financialContext(): array
    {
        $user = User::factory()->create();
        $account = Account::create(['user_id' => $user->id, 'name' => 'Conta', 'type' => 'checking', 'initial_balance' => '500.00']);
        $card = CreditCard::create(['user_id' => $user->id, 'payment_account_id' => $account->id, 'name' => 'Cartão', 'credit_limit' => '1000.00', 'closing_day' => 10, 'due_day' => 17]);

        return [$user, $account, $card, auth('api')->login($user)];
    }
}
