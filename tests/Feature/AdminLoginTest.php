<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_is_required(): void
    {
        $this->withoutVite();

        // ログイン対象の管理者を用意する
        User::factory()->create([
            'email' => 'admin@example.com',
            'admin_status' => true,
        ]);

        // メールアドレスだけ未入力にして管理者ログインする
        $response = $this->from('/admin/login')->post('/admin/login', [
            'email' => '',
            'password' => 'password',
        ]);

        $response->assertRedirect('/admin/login');
        $response->assertSessionHasErrors([
            'email' => 'メールアドレスを入力してください',
        ]);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSeeText('メールアドレスを入力してください');

        $this->assertGuest('web');
    }

    public function test_password_is_required(): void
    {
        $this->withoutVite();

        User::factory()->create([
            'email' => 'admin@example.com',
            'admin_status' => true,
        ]);

        // パスワードだけ未入力にして管理者ログインする
        $response = $this->from('/admin/login')->post('/admin/login', [
            'email' => 'admin@example.com',
            'password' => '',
        ]);

        $response->assertRedirect('/admin/login');
        $response->assertSessionHasErrors([
            'password' => 'パスワードを入力してください',
        ]);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSeeText('パスワードを入力してください');

        $this->assertGuest('web');
    }

    public function test_unregistered_email_cannot_log_in(): void
    {
        $this->withoutVite();

        User::factory()->create([
            'email' => 'admin@example.com',
            'admin_status' => true,
        ]);

        // 未登録のメールアドレスで管理者ログインする
        $response = $this->from('/admin/login')->post('/admin/login', [
            'email' => 'other-admin@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/admin/login');
        $response->assertSessionHasErrors([
            'email' => 'ログイン情報が登録されていません',
        ]);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSeeText('ログイン情報が登録されていません');

        $this->assertGuest('web');
    }
}
