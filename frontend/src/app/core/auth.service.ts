import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, finalize, map, shareReplay, tap } from 'rxjs';
import { environment } from '../../environments/environment';

export interface User { id: number; name: string; email: string; currency: string; locale: string; timezone: string }
interface AuthResponse { data: { access_token: string; user: User } }

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http=inject(HttpClient);private readonly router=inject(Router);
  private readonly tokenState = signal<string | null>(null);
  private readonly userState = signal<User | null>(null);
  private refreshRequest?: Observable<string>;
  readonly user = this.userState.asReadonly();
  readonly authenticated = computed(() => this.tokenState() !== null);
  get token(): string | null { return this.tokenState(); }

  login(email: string, password: string): Observable<void> { return this.authorize('auth/login', { email, password, device_name: navigator.userAgent }); }
  register(name: string, email: string, password: string, password_confirmation: string): Observable<void> { return this.authorize('auth/register', { name, email, password, password_confirmation, device_name: navigator.userAgent }); }
  refresh(): Observable<string> {
    if (!this.refreshRequest) this.refreshRequest = this.http.post<AuthResponse>(`${environment.apiUrl}/auth/refresh`, {}, { withCredentials: true }).pipe(tap(response => this.accept(response)), map(response => response.data.access_token), finalize(() => this.refreshRequest = undefined), shareReplay(1));
    return this.refreshRequest;
  }
  restore(): Observable<boolean> { return this.refresh().pipe(map(() => true)); }
  logout(): void { this.http.post(`${environment.apiUrl}/auth/logout`, {}, { withCredentials: true }).subscribe({ complete: () => this.clear(), error: () => this.clear() }); }
  clear(): void { this.tokenState.set(null); this.userState.set(null); void this.router.navigate(['/login']); }
  updateUser(user: User): void { this.userState.set(user); }
  private authorize(path: string, body: unknown): Observable<void> { return this.http.post<AuthResponse>(`${environment.apiUrl}/${path}`, body, { withCredentials: true }).pipe(tap(response => this.accept(response)), map(() => undefined)); }
  private accept(response: AuthResponse): void { this.tokenState.set(response.data.access_token); this.userState.set(response.data.user); }
}
