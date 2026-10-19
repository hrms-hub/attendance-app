<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_is_required(): void
    {
        $this->withoutVite();

        // ログイン対象の一般ユーザーを用意する
        User::factory()->create([
            'email' => 'user@example.com',
            'admin_status' => false,
        ]);

        // メールアドレスだけ未入力にしてログインする
        $response = $this->from('/login')->post('/login', [
            'email' => '',
            'password' => 'password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors([
            'email' => 'メールアドレスを入力してください',
        ]);

        $this->get('/login')
            ->assertOk()
            ->assertSeeText('メールアドレスを入力してください');

        $this->assertGuest('web');
    }

    public function test_password_is_required(): void
    {
        $this->withoutVite();

        User::factory()->create([
            'email' => 'user@example.com',
            'admin_status' => false,
        ]);

        // パスワードだけ未入力にしてログインする
        $response = $this->from('/login')->post('/login', [
            'email' => 'user@example.com',
            'password' => '',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors([
            'password' => 'パスワードを入力してください',
        ]);

        $this->get('/login')
            ->assertOk()
            ->assertSeeText('パスワードを入力してください');

        $this->assertGuest('web');
    }

    public function test_unregistered_email_cannot_log_in(): void
    {
        $this->withoutVite();

        User::factory()->create([
            'email' => 'user@example.com',
            'admin_status' => false,
        ]);

        // 登録されていないメールアドレスでログインする
        $response = $this->from('/login')->post('/login', [
            'email' => 'other@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors([
            'email' => 'ログイン情報が登録されていません',
        ]);

        $this->get('/login')
            ->assertOk()
            ->assertSeeText('ログイン情報が登録されていません');

        $this->assertGuest('web');
    }
}
