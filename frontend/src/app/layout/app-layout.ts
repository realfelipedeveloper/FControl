import { Component, inject, signal } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from '../core/auth.service';

@Component({
  selector:'app-layout',imports:[RouterOutlet,RouterLink,RouterLinkActive],
  template:`
  <div class="min-h-screen bg-slate-50">
    <aside class="fixed inset-y-0 left-0 z-40 flex w-72 flex-col overflow-y-auto bg-slate-950 p-5 text-slate-300 transition-transform lg:translate-x-0" [class.-translate-x-full]="!menuOpen()">
      <div class="flex items-center justify-between px-2 py-3"><a routerLink="/dashboard" class="text-2xl font-black text-white"><span class="text-emerald-400">F</span>Control</a><button class="lg:hidden" (click)="menuOpen.set(false)" aria-label="Fechar menu">✕</button></div>
      <nav class="mt-6 space-y-1" aria-label="Navegação principal">
        @for (item of navigation; track item.path) { <a [routerLink]="item.path" routerLinkActive="bg-emerald-500/15 text-emerald-300" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold hover:bg-white/5"><span aria-hidden="true">{{ item.icon }}</span>{{ item.label }}</a> }
      </nav>
      <button (click)="auth.logout()" class="mt-6 rounded-xl border border-slate-700 px-4 py-2.5 text-left text-sm font-semibold hover:bg-slate-900">↪ Encerrar sessão</button>
    </aside>
    <div class="lg:pl-72">
      <header class="sticky top-0 z-30 flex h-20 items-center justify-between border-b border-slate-200 bg-white/90 px-5 backdrop-blur sm:px-8"><button class="text-xl lg:hidden" (click)="menuOpen.set(true)" aria-label="Abrir menu">☰</button><div><p class="text-xs font-semibold uppercase tracking-wider text-slate-400">Área financeira</p><p class="font-bold text-slate-900">Olá, {{ auth.user()?.name ?? 'usuário' }}</p></div><div class="grid h-10 w-10 place-items-center rounded-full bg-emerald-100 font-black text-emerald-800">{{ (auth.user()?.name ?? 'U').charAt(0) }}</div></header>
      <main class="p-5 sm:p-8"><router-outlet /></main>
    </div>
  </div>`
})
export class AppLayout {
  readonly auth=inject(AuthService);
  readonly menuOpen=signal(false);
  readonly navigation=[
    {path:'/dashboard',label:'Visão geral',icon:'⌂'},{path:'/contas',label:'Contas',icon:'◉'},{path:'/movimentacoes',label:'Movimentações',icon:'↕'},{path:'/receitas',label:'Receitas',icon:'↗'},{path:'/despesas',label:'Despesas',icon:'↘'},{path:'/transferencias',label:'Transferências',icon:'⇄'},{path:'/cartoes',label:'Cartões e faturas',icon:'▣'},{path:'/orcamentos',label:'Orçamentos',icon:'◎'},{path:'/metas',label:'Metas',icon:'◆'},{path:'/recorrencias',label:'Recorrências',icon:'⟳'},{path:'/categorias',label:'Categorias',icon:'▦'},{path:'/tags',label:'Tags',icon:'#'},{path:'/relatorios',label:'Relatórios',icon:'▤'},{path:'/configuracoes',label:'Configurações',icon:'⚙'}];
}
