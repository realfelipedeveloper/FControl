import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, switchMap, throwError } from 'rxjs';
import { AuthService } from './auth.service';

export const authInterceptor: HttpInterceptorFn = (request, next) => {
  const auth = inject(AuthService);
  const withToken = auth.token ? request.clone({ setHeaders: { Authorization: `Bearer ${auth.token}` }, withCredentials: true }) : request.clone({ withCredentials: true });
  return next(withToken).pipe(catchError((error: HttpErrorResponse) => {
    if (error.status !== 401 || request.url.includes('/auth/refresh') || request.url.includes('/auth/login')) return throwError(() => error);
    return auth.refresh().pipe(switchMap(token => next(request.clone({ setHeaders: { Authorization: `Bearer ${token}` }, withCredentials: true }))), catchError(refreshError => { auth.clear(); return throwError(() => refreshError); }));
  }));
};
