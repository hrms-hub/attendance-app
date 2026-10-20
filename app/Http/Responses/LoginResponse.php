<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * 権限とメール認証状態に応じた画面へ遷移する。
     *
     * @param  Request  $request  ログインリクエスト
     * @return RedirectResponse 遷移先へのリダイレクト
     */
    public function toResponse(mixed $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->admin_status) {
            return redirect('/admin/attendance/list');
        }

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect('/attendance');
    }
}
