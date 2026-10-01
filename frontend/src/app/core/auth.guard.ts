import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { catchError, map, of } from 'rxjs';
import { AuthService } from './auth.service';
export const authGuard: CanActivateFn = () => { const auth=inject(AuthService),router=inject(Router); if(auth.authenticated())return true; return auth.restore().pipe(map(()=>true),catchError(()=>of(router.createUrlTree(['/login'])))); };
