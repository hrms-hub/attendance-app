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
    public function create(): RegisterViewResponse
    {
        return app(RegisterViewResponse::class);
    }

    public function store(
        RegisterRequest $request,
        CreatesNewUsers $creator
    ): RedirectResponse {
        $user = $creator->create($request->validated());

        event(new Registered($user));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/attendance');
    }
}