import { expect, test } from '@playwright/test';

test('fluxo financeiro pessoal completo', async ({ request, context, page, baseURL }) => {
  const appUrl=baseURL??'http://localhost:8080';
  const email=`e2e-${Date.now()}@fcontrol.local`;
  const registration=await request.post('/api/v1/auth/register',{data:{name:'Usuário E2E',email,password:'SenhaForte123',password_confirmation:'SenhaForte123'}});
  expect(registration.status()).toBe(201);const auth=await registration.json();const headers={Authorization:`Bearer ${auth.data.access_token}`};
  const cookieHeader=registration.headers()['set-cookie'];const cookieValue=/fcontrol_refresh=([^;]+)/.exec(cookieHeader)?.[1];if(cookieValue)await context.addCookies([{name:'fcontrol_refresh',value:cookieValue,url:`${appUrl}/api/v1/auth`}]);
  const categories=await (await request.get('/api/v1/categories',{headers})).json();const expenseCategory=categories.data.find((c:{type:string})=>c.type==='expense');const incomeCategory=categories.data.find((c:{type:string})=>c.type==='income');
  const accountA=await (await request.post('/api/v1/accounts',{headers,data:{name:'Conta Principal',type:'checking',initial_balance:'1000.00'}})).json();
  const accountB=await (await request.post('/api/v1/accounts',{headers,data:{name:'Reserva',type:'savings',initial_balance:'0.00'}})).json();
  await request.post('/api/v1/transactions',{headers,data:{account_id:accountA.data.id,category_id:incomeCategory.id,type:'income',description:'Salário',amount:'3000.00',transaction_date:'2026-09-01',competence_date:'2026-09-01',status:'received'}});
  await request.post('/api/v1/transactions',{headers,data:{account_id:accountA.data.id,category_id:expenseCategory.id,type:'expense',description:'Mercado',amount:'250.00',transaction_date:'2026-09-02',competence_date:'2026-09-02',status:'paid'}});
  expect((await request.post('/api/v1/transfers',{headers,data:{from_account_id:accountA.data.id,to_account_id:accountB.data.id,amount:'500.00',transferred_at:'2026-09-03'}})).status()).toBe(201);
  const card=await (await request.post('/api/v1/cards',{headers,data:{name:'Cartão E2E',payment_account_id:accountA.data.id,credit_limit:'5000.00',closing_day:10,due_day:17}})).json();
  expect((await request.post('/api/v1/card-purchases',{headers,data:{credit_card_id:card.data.id,category_id:expenseCategory.id,description:'Notebook',total_amount:'1200.00',installment_count:6,purchased_at:'2026-09-04'}})).status()).toBe(201);
  expect((await request.get('/api/v1/invoices',{headers})).status()).toBe(200);
  expect((await request.post('/api/v1/budgets',{headers,data:{category_id:expenseCategory.id,month:'2026-09-01',planned_amount:'1500.00'}})).status()).toBe(201);
  expect((await request.post('/api/v1/goals',{headers,data:{name:'Viagem',target_amount:'8000.00',current_amount:'500.00',status:'active'}})).status()).toBe(201);
  expect((await request.get('/api/v1/dashboard',{headers})).status()).toBe(200);
  await page.goto('/dashboard');await expect(page.getByRole('heading',{name:'Visão geral'})).toBeVisible();await page.getByRole('button',{name:/Encerrar sessão/}).click();await expect(page).toHaveURL(/\/login/);
});
