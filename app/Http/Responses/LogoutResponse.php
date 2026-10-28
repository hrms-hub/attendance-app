<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Http\RedirectResponse;

class LogoutResponse implements LogoutResponseContract
{
   public function toResponse(mixed $request): RedirectResponse
    {
        return redirect(
            $request->is('admin/*')
                ? '/admin/login'
                : '/login'
        );
    }
}
