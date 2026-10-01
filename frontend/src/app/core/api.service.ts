import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface ApiItem { id: number; [key: string]: unknown }
export interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }

@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http=inject(HttpClient);
  get<T>(path: string, params?: Record<string, string | number | boolean>): Observable<T> {
    let httpParams = new HttpParams(); Object.entries(params ?? {}).forEach(([key, value]) => httpParams = httpParams.set(key, String(value)));
    return this.http.get<T>(`${environment.apiUrl}/${path}`, { params: httpParams });
  }
  download(path: string, params?: Record<string, string | number | boolean>): Observable<Blob> {
    let httpParams = new HttpParams(); Object.entries(params ?? {}).forEach(([key, value]) => httpParams = httpParams.set(key, String(value)));
    return this.http.get(`${environment.apiUrl}/${path}`, { params: httpParams, responseType: 'blob' });
  }
  post<T>(path: string, body: unknown): Observable<T> { return this.http.post<T>(`${environment.apiUrl}/${path}`, body); }
  patch<T>(path: string, body: unknown): Observable<T> { return this.http.patch<T>(`${environment.apiUrl}/${path}`, body); }
  delete<T>(path: string): Observable<T> { return this.http.delete<T>(`${environment.apiUrl}/${path}`); }
}
