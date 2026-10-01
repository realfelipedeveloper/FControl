import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { describe, expect, it, vi } from 'vitest';
import { environment } from '../../environments/environment';
import { AuthService } from './auth.service';

describe('AuthService', () => {
  function setup() {
    localStorage.clear();
    TestBed.configureTestingModule({providers:[provideHttpClient(),provideHttpClientTesting(),{provide:Router,useValue:{navigate:vi.fn()}}]});
    return {service:TestBed.inject(AuthService),http:TestBed.inject(HttpTestingController)};
  }

  it('mantém o access token somente no estado em memória', () => {
    const {service,http}=setup();let completed=false;
    service.login('usuario@teste.local','SenhaForte123').subscribe(()=>completed=true);
    const request=http.expectOne(`${environment.apiUrl}/auth/login`);expect(request.request.withCredentials).toBe(true);request.flush({data:{access_token:'jwt-em-memoria',user:{id:1,name:'Usuário',email:'usuario@teste.local',currency:'BRL',locale:'pt-BR',timezone:'America/Sao_Paulo'}}});
    expect(completed).toBe(true);expect(service.token).toBe('jwt-em-memoria');expect(localStorage.length).toBe(0);http.verify();
  });

  it('compartilha uma única requisição entre refreshes concorrentes', () => {
    const {service,http}=setup();const tokens:string[]=[];
    service.refresh().subscribe(token=>tokens.push(token));service.refresh().subscribe(token=>tokens.push(token));
    const request=http.expectOne(`${environment.apiUrl}/auth/refresh`);request.flush({data:{access_token:'novo-token',user:{id:1,name:'Usuário',email:'usuario@teste.local',currency:'BRL',locale:'pt-BR',timezone:'America/Sao_Paulo'}}});
    expect(tokens).toEqual(['novo-token','novo-token']);http.verify();
  });
});
