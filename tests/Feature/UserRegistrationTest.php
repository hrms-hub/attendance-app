<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_is_required(): void
    {
        // Viteを起動していなくても画面を検証できるようにする
        $this->withoutVite();

        // 名前だけ未入力にして会員登録する
        $response = $this->from('/register')->post('/register', [
            'name' => '',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        // 登録画面に戻り、指定のエラーがあることを確認する
        $response->assertRedirect('/register');
        $response->assertSessionHasErrors([
            'name' => 'お名前を入力してください',
        ]);

        // 登録画面にエラーメッセージが表示されることを確認する
        $this->get('/register')
            ->assertOk()
            ->assertSeeText('お名前を入力してください');

        // ユーザーが保存されず、未ログインであることを確認する
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_email_is_required(): void
    {
        $this->withoutVite();

        // メールアドレスだけ未入力にして会員登録する
        $response = $this->from('/register')->post('/register', [
            'name' => 'テストユーザー',
            'email' => '',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors([
            'email' => 'メールアドレスを入力してください',
        ]);

        $this->get('/register')
            ->assertOk()
            ->assertSeeText('メールアドレスを入力してください');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_password_must_be_at_least_eight_characters(): void
    {
        $this->withoutVite();

        $response = $this->from('/register')->post('/register', [
            'name' => 'テストユーザー',
            'email' => 'test@example.com',
            'password' => 'pass123',
            'password_confirmation' => 'pass123',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors([
            'password' => 'パスワードは8文字以上で入力してください',
        ]);

        $this->get('/register')
            ->assertOk()
            ->assertSeeText('パスワードは8文字以上で入力してください');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_password_confirmation_must_match(): void
    {
        $this->withoutVite();

        $response = $this->from('/register')->post('/register', [
            'name' => 'テストユーザー',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password456',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors([
            'password_confirmation' => 'パスワードと一致しません',
        ]);

        $this->get('/register')
            ->assertOk()
            ->assertSeeText('パスワードと一致しません');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_password_is_required(): void
    {
        $this->withoutVite();

        // パスワードだけ未入力にして会員登録する
        $response = $this->from('/register')->post('/register', [
            'name' => 'テストユーザー',
            'email' => 'test@example.com',
            'password' => '',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors([
            'password' => 'パスワードを入力してください',
        ]);

        $this->get('/register')
            ->assertOk()
            ->assertSeeText('パスワードを入力してください');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_user_can_register(): void
    {
        // テスト中は実際のメール送信を行わない
        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'テストユーザー',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('verification.notice'));

        // メール未認証の一般ユーザーとして保存されている
        $this->assertDatabaseHas('users', [
            'name' => 'テストユーザー',
            'email' => 'test@example.com',
            'admin_status' => false,
            'email_verified_at' => null,
        ]);

        $this->assertDatabaseCount('users', 1);

        $user = User::where('email', 'test@example.com')->firstOrFail();

        // パスワードが正しくハッシュ化されている
        $this->assertTrue(Hash::check('password', $user->password));

        // 登録したユーザーとしてログインしている
        $this->assertAuthenticatedAs($user, 'web');
    }
}
