<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $user = $request->user();

        return redirect(
            $user->admin_status
                ? '/admin/attendance/list'
                : '/attendance'
        );
    }
}