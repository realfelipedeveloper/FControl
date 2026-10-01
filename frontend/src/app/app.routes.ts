import { Routes } from '@angular/router';
import { authGuard } from './core/auth.guard';

export const routes: Routes = [
  {path:'login',loadComponent:()=>import('./features/auth/auth-page').then(module=>module.AuthPage),data:{mode:'login'}},
  {path:'cadastro',loadComponent:()=>import('./features/auth/auth-page').then(module=>module.AuthPage),data:{mode:'register'}},
  {path:'recuperar-senha',loadComponent:()=>import('./features/auth/recovery-page').then(module=>module.RecoveryPage),data:{mode:'forgot'}},
  {path:'redefinir-senha',loadComponent:()=>import('./features/auth/recovery-page').then(module=>module.RecoveryPage),data:{mode:'reset'}},
  {path:'',loadComponent:()=>import('./layout/app-layout').then(module=>module.AppLayout),canActivate:[authGuard],children:[
    {path:'dashboard',loadComponent:()=>import('./features/dashboard/dashboard').then(module=>module.Dashboard)},
    {path:'contas',loadComponent:()=>import('./features/crud/resource-page').then(module=>module.ResourcePage),data:{endpoint:'accounts',title:'Contas',description:'Acompanhe seus saldos por conta.'}},
    {path:'movimentacoes',loadComponent:()=>import('./features/crud/resource-page').then(module=>module.ResourcePage),data:{endpoint:'transactions',title:'Movimentações',description:'Receitas e despesas em um único histórico.'}},
    {path:'receitas',loadComponent:()=>import('./features/crud/resource-page').then(module=>module.ResourcePage),data:{endpoint:'transactions',title:'Receitas',description:'Valores recebidos e previstos.',presetType:'income'}},
    {path:'despesas',loadComponent:()=>import('./features/crud/resource-page').then(module=>module.ResourcePage),data:{endpoint:'transactions',title:'Despesas',description:'Acompanhe os gastos por competência.',presetType:'expense'}},
    {path:'categorias',loadComponent:()=>import('./features/crud/resource-page').then(module=>module.ResourcePage),data:{endpoint:'categories',title:'Categorias e subcategorias',description:'Organize receitas e despesas em uma hierarquia.'}},
    {path:'tags',loadComponent:()=>import('./features/crud/resource-page').then(module=>module.ResourcePage),data:{endpoint:'tags',title:'Tags',description:'Crie marcadores personalizados para seus lançamentos.'}},
    {path:'recorrencias',loadComponent:()=>import('./features/recurrences/recurrences-page').then(module=>module.RecurrencesPage)},
    {path:'transferencias',loadComponent:()=>import('./features/crud/resource-page').then(module=>module.ResourcePage),data:{endpoint:'transfers',title:'Transferências',description:'Movimente valores entre suas próprias contas.',allowDelete:false}},
    {path:'cartoes',loadComponent:()=>import('./features/cards/cards-page').then(module=>module.CardsPage)},
    {path:'orcamentos',loadComponent:()=>import('./features/crud/resource-page').then(module=>module.ResourcePage),data:{endpoint:'budgets',title:'Orçamentos',description:'Defina limites mensais por categoria.'}},
    {path:'metas',loadComponent:()=>import('./features/goals/goals-page').then(module=>module.GoalsPage)},
    {path:'relatorios',loadComponent:()=>import('./features/reports/reports').then(module=>module.Reports)},
    {path:'configuracoes',loadComponent:()=>import('./features/settings/settings').then(module=>module.Settings)},
    {path:'',pathMatch:'full',redirectTo:'dashboard'},
  ]},
  {path:'**',redirectTo:'dashboard'},
];
