import { Component, OnInit, inject, input, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { AuthService } from '../../core/auth.service';

@Component({
  selector: 'app-auth-page', imports: [ReactiveFormsModule, RouterLink],
  template: `
  <main class="grid min-h-screen lg:grid-cols-2">
    <section class="hidden bg-slate-950 p-14 text-white lg:flex lg:flex-col lg:justify-between">
      <a routerLink="/" class="text-2xl font-black tracking-tight"><span class="text-emerald-400">F</span>Control</a>
      <div><p class="mb-5 text-sm font-bold uppercase tracking-[.25em] text-emerald-400">Finanças com clareza</p><h1 class="max-w-xl text-5xl font-black leading-tight">Seu dinheiro organizado.<br>Suas escolhas no controle.</h1><p class="mt-6 max-w-lg text-lg text-slate-300">Contas, lançamentos, cartões, metas e orçamento em um só lugar, sem distrações.</p></div>
      <p class="text-sm text-slate-500">Privacidade e segurança desde a primeira linha.</p>
    </section>
    <section class="flex items-center justify-center p-6 sm:p-12">
      <div class="w-full max-w-md">
        <a routerLink="/" class="mb-10 block text-2xl font-black lg:hidden"><span class="text-emerald-600">F</span>Control</a>
        <p class="text-sm font-bold uppercase tracking-widest text-emerald-700">{{ mode() === 'login' ? 'Bem-vindo de volta' : 'Comece agora' }}</p>
        <h2 class="mt-2 text-3xl font-black text-slate-950">{{ mode() === 'login' ? 'Acesse sua conta' : 'Crie sua conta gratuita' }}</h2>
        <form class="mt-8 space-y-5" [formGroup]="form" (ngSubmit)="submit()">
          @if (mode() === 'register') { <label class="block"><span class="label">Nome</span><input class="field" formControlName="name" autocomplete="name"></label> }
          <label class="block"><span class="label">E-mail</span><input class="field" formControlName="email" type="email" autocomplete="email"></label>
          <label class="block"><span class="label">Senha</span><input class="field" formControlName="password" type="password" [autocomplete]="mode() === 'login' ? 'current-password' : 'new-password'"></label>
          @if (mode() === 'register') { <label class="block"><span class="label">Confirme a senha</span><input class="field" formControlName="password_confirmation" type="password" autocomplete="new-password"></label> }
          @if (error()) { <p role="alert" class="rounded-xl bg-red-50 p-3 text-sm font-medium text-red-700">{{ error() }}</p> }
          @if (mode() === 'login') { <a routerLink="/recuperar-senha" class="block text-right text-sm font-bold text-emerald-700">Esqueci minha senha</a> }
          <button class="btn-primary w-full" [disabled]="form.invalid || loading()">{{ loading() ? 'Aguarde…' : (mode() === 'login' ? 'Entrar' : 'Criar conta') }}</button>
        </form>
        <p class="mt-6 text-center text-sm text-slate-600">{{ mode() === 'login' ? 'Ainda não possui conta?' : 'Já possui uma conta?' }} <a class="font-bold text-emerald-700" [routerLink]="mode() === 'login' ? '/cadastro' : '/login'">{{ mode() === 'login' ? 'Cadastre-se' : 'Entrar' }}</a></p>
      </div>
    </section>
  </main>`
})
export class AuthPage implements OnInit {
  private readonly fb=inject(FormBuilder);private readonly auth=inject(AuthService);private readonly router=inject(Router);
  readonly mode=input<'login'|'register'>('login'); loading=signal(false); error=signal('');
  readonly form=this.fb.nonNullable.group({name:[''],email:['',[Validators.required,Validators.email]],password:['',[Validators.required,Validators.minLength(10)]],password_confirmation:['']});
  ngOnInit():void{if(this.mode()==='register'){this.form.controls.name.addValidators(Validators.required);this.form.controls.password_confirmation.addValidators(Validators.required);this.form.controls.name.updateValueAndValidity();this.form.controls.password_confirmation.updateValueAndValidity();}}
  submit():void { if(this.form.invalid)return;this.loading.set(true);this.error.set('');const v=this.form.getRawValue();const request=this.mode()==='login'?this.auth.login(v.email,v.password):this.auth.register(v.name,v.email,v.password,v.password_confirmation);request.pipe(finalize(()=>this.loading.set(false))).subscribe({next:()=>void this.router.navigate(['/dashboard']),error:e=>this.error.set(e.error?.message??'Não foi possível continuar.')}); }
}
