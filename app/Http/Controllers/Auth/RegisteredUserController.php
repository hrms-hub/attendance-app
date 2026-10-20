<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterViewResponse;

class RegisteredUserController extends Controller
{
    /**
     * Fortifyに設定された会員登録画面のレスポンスを返す。
     *
     * @return RegisterViewResponse 会員登録画面のレスポンス
     */
    public function create(): RegisterViewResponse
    {
        return app(RegisterViewResponse::class);
    }

    /**
     * 会員登録と認証メール送信を行い、ログインして認証案内へ案内する。
     *
     * @param  RegisterRequest  $request  検証済みの会員登録入力
     * @param  CreatesNewUsers  $creator  ユーザーの作成処理
     * @return RedirectResponse メール認証案内画面への遷移
     */
    public function store(
        RegisterRequest $request,
        CreatesNewUsers $creator
    ): RedirectResponse {
        $user = $creator->create($request->validated());

        event(new Registered($user));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
