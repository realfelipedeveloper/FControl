# FControl

Aplicação web de controle financeiro pessoal. O FControl reúne contas, receitas, despesas, transferências, cartões e faturas, orçamentos, metas, recorrências, anexos, dashboard e relatórios em um monólito modular simples.

## Stack

- API REST `/api/v1`: PHP 8.5, Laravel 13, Eloquent e JWT.
- Interface: Angular 22 standalone, TypeScript 6, Tailwind CSS 4 e Chart.js.
- Dados e execução: MySQL 8.4 LTS, PHP-FPM, Nginx e Docker Compose.
- Qualidade: PHPUnit, Pint, Larastan/PHPStan, Vitest, ESLint, Playwright, Composer Audit e pnpm Audit.

## Pré-requisitos

Para o caminho recomendado, basta Docker 28+ com Compose v2. O desenvolvimento sem Docker requer PHP 8.5, Composer 2.9, Node 24.15+ e pnpm 10.17.1.

## Início rápido

```powershell
Copy-Item .env.example .env
# Defina APP_KEY, JWT_SECRET e as senhas no .env antes de qualquer ambiente compartilhado.
docker compose up -d --build
```

A aplicação fica em `http://localhost:8080` e o healthcheck em `http://localhost:8080/api/v1/health`. Na primeira subida, o backend aplica as migrations. Para dados locais de demonstração:

```powershell
docker compose exec backend php artisan db:seed
```

O seeder roda somente em `local`/`testing` e cria `demo@fcontrol.local` com senha `FControl@12345`. Troque ou remova esses dados fora do ambiente local.

## Estrutura

```text
backend/             API Laravel e testes PHPUnit
frontend/            aplicação Angular e E2E Playwright
docker/              imagens e configuração Nginx
scripts/             backup e restauração do MySQL
.github/              CI, segurança, Git Flow, CD e Dependabot
docker-compose.yml   ambiente integrado
```

## Comandos principais

Backend em execução (as imagens finais não incluem dependências de desenvolvimento):

```powershell
docker compose exec backend php artisan migrate --force
docker run --rm -v "${PWD}/backend:/app" -w /app composer:2.9 audit --locked
```

Para testes e análise, use PHP 8.5/Composer localmente, instale `composer install` em `backend/` e configure um MySQL **exclusivo de testes** chamado `fcontrol_test` (host, usuário e senha via ambiente). Execute `php artisan test`, `vendor/bin/pint --test` e `vendor/bin/phpstan analyse --memory-limit=1G`. O CI prepara esse banco automaticamente. Os testes recriam tabelas: nunca use o banco da aplicação. `migrate:fresh --seed` também é destrutivo e não faz parte da inicialização normal.

Frontend local:

```powershell
Set-Location frontend
pnpm install --frozen-lockfile
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm e2e
```

## Configuração

Copie `.env.example` na raiz para `.env`. Nunca versione esse arquivo. `APP_KEY` e `JWT_SECRET` devem ser valores aleatórios independentes. No backend, uma chave JWT pode ser criada com `php artisan jwt:secret`; no Compose, forneça o valor pelo `.env` raiz. Configure `CORS_ALLOWED_ORIGINS` no ambiente do backend com origens explícitas.

O access token JWT dura 15 minutos e permanece apenas em memória no navegador. O refresh token é aleatório, salvo somente como hash, enviado em cookie `HttpOnly`/`SameSite=Strict`, rotacionado a cada uso e protegido contra reutilização. Em produção o cookie recebe `Secure`.

## Banco, arquivos e scheduler

Valores monetários usam `DECIMAL(15,2)` e cálculos de parcelamento usam centavos inteiros. Transferências, compras parceladas, pagamento de fatura, metas e recorrências usam transações de banco. O scheduler materializa recorrências diariamente e usa chave idempotente. Anexos são privados, limitados a 5 MB e exigem autenticação para download.

O resultado mensal usa competência; o saldo usa liquidações efetivas. A projeção do fechamento inclui pendências vencidas e compromissos até o fim do mês, sem descontar faturas futuras. O patrimônio desconta toda a dívida de cartão em aberto. O histórico é reconstruído com os saldos iniciais cadastrados, datas de liquidação, compras e pagamentos; alterações retroativas recalculam a série (não são snapshots contábeis). Contas inativas continuam compondo o patrimônio. Pagamentos de fatura não podem ser editados/excluídos como lançamentos avulsos.

Recorrências atrasadas avançam uma ocorrência por execução; o comando `php artisan fcontrol:materialize-recurrences` permite executar outra rodada. Limites, término, dia-base e idempotência são preservados, inclusive sob execução concorrente.

Backup e restauração no Windows:

```powershell
.\scripts\backup-db.ps1
.\scripts\restore-db.ps1 -BackupFile .\backups\fcontrol-AAAAMMDD-HHMMSS.sql
```

Os dumps permanecem em `backups/`, ignorado pelo Git.

## API e segurança

Todos os endpoints financeiros exigem JWT e derivam `user_id` do usuário autenticado. Consultas de recurso sempre incluem esse proprietário e Policies adicionam uma segunda camada de autorização, prevenindo IDOR. Form Requests, Enums e API Resources centralizam contratos importantes. A API aplica validação server-side, limites separados para autenticação e renovação de sessão, proteção contra brute force, soft delete, auditoria de eventos, CORS restritivo, headers de segurança e respostas sem stack trace em produção.

Endpoints principais: `auth`, `accounts`, `categories`, `tags`, `transactions`, `transfers`, `cards`, `card-purchases`, `invoices`, `budgets`, `goals`, `recurrences`, `attachments`, `dashboard` e `reports` sob `/api/v1`.

## Git Flow e CI/CD

A ferramenta local `agents/engineering_loop.py` coordena checks com OpenAI Agents SDK, separada da aplicação e ignorada pelo Git conforme a regra dos artefatos de engenharia. No workspace onde foi preparada: `uv run agents/engineering_loop.py --self-test` valida o contrato sem API; `--offline` executa os checks locais (requer MySQL de testes, dependências de desenvolvimento em `backend/vendor` e imagem `fcontrol-frontend-build:latest` atualizada). Para revisão com modelo, configure `OPENAI_API_KEY` no ambiente e informe `--model`. Somente nomes/status dos checks são enviados; logs ficam locais e tracing está desabilitado. A aprovação depende dos códigos de saída, não da resposta do modelo. A aplicação financeira não usa IA.

O fluxo aceito é `feature/*`, `fix/*` ou `chore/*` para `develop`, e somente `develop` para `main`. O workflow de validação bloqueia combinações diferentes. Push em `develop` abre, sem duplicar, uma PR manual para `main`. O merge nunca é automático.

Os pipelines validam backend, frontend, integração MySQL, E2E, secrets e vulnerabilidades. Em `main`, as imagens `fcontrol-backend` e `fcontrol-frontend` são publicadas no GHCR com tags `latest` e SHA. O deploy em servidor não faz parte desta versão.

## Licença e privacidade

Dados financeiros são sensíveis. Use HTTPS, segredos fortes, backups cifrados e proteção de branches antes de produção. O sistema não armazena número completo de cartão, CVV nem credenciais bancárias.
