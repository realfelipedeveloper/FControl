import { Routes } from '@angular/router';
import { authGuard } from './core/auth.guard';
import { AuthPage } from './features/auth/auth-page';
import { ResourcePage } from './features/crud/resource-page';
import { Dashboard } from './features/dashboard/dashboard';
import { Reports } from './features/reports/reports';
import { Settings } from './features/settings/settings';
import { AppLayout } from './layout/app-layout';

export const routes: Routes = [
  {path:'login',component:AuthPage,data:{mode:'login'}},{path:'cadastro',component:AuthPage,data:{mode:'register'}},
  {path:'',component:AppLayout,canActivate:[authGuard],children:[
    {path:'dashboard',component:Dashboard},
    {path:'contas',component:ResourcePage,data:{endpoint:'accounts',title:'Contas',description:'Acompanhe seus saldos por conta.'}},
    {path:'movimentacoes',component:ResourcePage,data:{endpoint:'transactions',title:'Movimentações',description:'Receitas e despesas em um único histórico.'}},
    {path:'receitas',component:ResourcePage,data:{endpoint:'transactions',title:'Receitas',description:'Valores recebidos e previstos.',presetType:'income'}},
    {path:'despesas',component:ResourcePage,data:{endpoint:'transactions',title:'Despesas',description:'Acompanhe os gastos por competência.',presetType:'expense'}},
    {path:'transferencias',component:ResourcePage,data:{endpoint:'transfers',title:'Transferências',description:'Movimente valores entre suas próprias contas.',allowDelete:false}},
    {path:'cartoes',component:ResourcePage,data:{endpoint:'cards',title:'Cartões de crédito',description:'Controle limites, fechamento e vencimento.'}},
    {path:'orcamentos',component:ResourcePage,data:{endpoint:'budgets',title:'Orçamentos',description:'Defina limites mensais por categoria.'}},
    {path:'metas',component:ResourcePage,data:{endpoint:'goals',title:'Metas financeiras',description:'Transforme planos em progresso mensurável.'}},
    {path:'relatorios',component:Reports},{path:'configuracoes',component:Settings},{path:'',pathMatch:'full',redirectTo:'dashboard'}
  ]},
  {path:'**',redirectTo:'dashboard'}
];
