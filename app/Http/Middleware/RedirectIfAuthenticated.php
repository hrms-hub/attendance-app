<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    public function handle(
        Request $request,
        Closure $next,
        string ...$guards
    ): Response {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                $user = Auth::guard($guard)->user();

                if (! $user->admin_status && ! $user->hasVerifiedEmail()) {
                    return redirect()->route('verification.notice');
                }

                return redirect(
                    $user->admin_status
                        ? '/admin/attendance/list'
                        : '/attendance'
                );
            }
        }

        return $next($request);
    }
}
