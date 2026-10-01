import { Component, OnInit, inject, input, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';
import { ApiService } from '../../core/api.service';

@Component({
  selector: 'app-recovery-page',
  imports: [ReactiveFormsModule, RouterLink],
  template: `
    <main class="grid min-h-screen place-items-center bg-slate-50 p-6">
      <section class="card w-full max-w-md p-8">
        <a routerLink="/login" class="text-2xl font-black"><span class="text-emerald-600">F</span>Control</a>
        <p class="mt-8 text-sm font-bold uppercase tracking-widest text-emerald-700">Segurança</p>
        <h1 class="mt-2 text-3xl font-black">{{ mode() === 'forgot' ? 'Recuperar acesso' : 'Definir nova senha' }}</h1>
        <p class="mt-2 text-sm text-slate-500">{{ mode() === 'forgot' ? 'Informe seu e-mail para receber as instruções.' : 'Escolha uma senha forte para concluir.' }}</p>
        <form class="mt-7 space-y-4" [formGroup]="form" (ngSubmit)="submit()">
          <label><span class="label">E-mail</span><input class="field" type="email" autocomplete="email" formControlName="email"></label>
          @if(mode() === 'reset') {
            <label><span class="label">Token recebido</span><input class="field" formControlName="token"></label>
            <label><span class="label">Nova senha</span><input class="field" type="password" autocomplete="new-password" formControlName="password"></label>
            <label><span class="label">Confirme a senha</span><input class="field" type="password" autocomplete="new-password" formControlName="password_confirmation"></label>
          }
          @if(message()){<p class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{message()}}</p>}
          @if(error()){<p class="rounded-xl bg-red-50 p-3 text-sm text-red-700" role="alert">{{error()}}</p>}
          <button class="btn-primary w-full" [disabled]="form.invalid||loading()">{{loading()?'Aguarde…':(mode()==='forgot'?'Enviar instruções':'Redefinir senha')}}</button>
        </form>
        <a routerLink="/login" class="mt-6 block text-center text-sm font-bold text-emerald-700">Voltar ao login</a>
      </section>
    </main>
  `,
})
export class RecoveryPage implements OnInit {
  private readonly fb=inject(FormBuilder);private readonly api=inject(ApiService);private readonly route=inject(ActivatedRoute);
  readonly mode=input<'forgot'|'reset'>('forgot');readonly loading=signal(false);readonly message=signal('');readonly error=signal('');
  readonly form=this.fb.nonNullable.group({email:[this.route.snapshot.queryParamMap.get('email')??'',[Validators.required,Validators.email]],token:[this.route.snapshot.queryParamMap.get('token')??''],password:['',Validators.minLength(10)],password_confirmation:['']});
  ngOnInit():void{if(this.mode()==='reset'){for(const control of [this.form.controls.token,this.form.controls.password,this.form.controls.password_confirmation]){control.addValidators(Validators.required);control.updateValueAndValidity();}}}
  submit():void{if(this.form.invalid)return;this.loading.set(true);this.message.set('');this.error.set('');const value=this.form.getRawValue();const request=this.mode()==='forgot'?this.api.post<{message:string}>('auth/forgot-password',{email:value.email}):this.api.post<{message:string}>('auth/reset-password',value);request.pipe(finalize(()=>this.loading.set(false))).subscribe({next:r=>this.message.set(r.message),error:e=>this.error.set(e.error?.message??'Não foi possível concluir a solicitação.')});}
}
