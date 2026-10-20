<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_verification_email_is_sent_after_registration(): void
    {
        // 準備・実行：新しい一般ユーザーを登録する
        $response = $this->post('/register', [
            'name' => 'メール認証テスト',
            'email' => 'verify@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        // 検証：認証誘導画面へ遷移する
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'verify@example.com')->firstOrFail();

        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user, 'web');

        // 登録したユーザー宛てに認証通知が1件送信される
        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertCount(1);
    }

    public function test_verification_notice_links_to_mailpit(): void
    {
        // 準備：メール未認証の一般ユーザーを作成する
        $user = User::factory()->unverified()->create([
            'admin_status' => false,
        ]);

        // 実行：認証誘導画面を表示する
        $response = $this->actingAs($user, 'web')
            ->get('/email/verify');

        $response->assertOk();
        $response->assertSeeText('認証はこちらから');

        // 検証：ボタンのリンク先がMailpitである
        $document = new DOMDocument;
        $document->loadHTML(
            '<?xml encoding="UTF-8">'.$response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new DOMXPath($document);

        $this->assertSame(
            'http://localhost:8025',
            $xpath->evaluate(
                "string(//a[normalize-space(.)='認証はこちらから']/@href)"
            )
        );
    }

    public function test_email_verification_redirects_to_attendance(): void
    {
        // 準備：未認証ユーザーと有効な認証URLを用意する
        $user = User::factory()->unverified()->create([
            'admin_status' => false,
        ]);

        $verificationUrl = $this->verificationUrl($user);

        // 実行：メール本文の認証リンクに相当するURLへアクセスする
        $response = $this->actingAs($user, 'web')
            ->get($verificationUrl);

        // 検証：認証日時が保存され、勤怠登録画面へ遷移する
        $response->assertRedirect('/attendance');

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->get('/attendance')->assertOk();
    }

    public function test_unverified_user_can_resend_verification_email(): void
    {
        // 準備：認証誘導画面を開いている未認証ユーザー
        $user = User::factory()->unverified()->create([
            'admin_status' => false,
        ]);

        // 実行：認証メールの再送を要求する
        $response = $this->actingAs($user, 'web')
            ->from('/email/verify')
            ->post('/email/verification-notification');

        // 検証：認証通知が送信され、成功メッセージが表示される
        $response->assertRedirect('/email/verify');
        $response->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertCount(1);

        $this->get('/email/verify')
            ->assertOk()
            ->assertSeeText('認証メールを再送しました。');

        // 再送だけではメール認証済みにならない
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_unverified_login_redirects_to_verification_notice(): void
    {
        // 準備：まだメール認証を完了していないユーザー
        $user = User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
            'admin_status' => false,
        ]);

        // 実行：正しい情報でログインする
        $response = $this->post('/login', [
            'email' => 'unverified@example.com',
            'password' => 'password',
        ]);

        // 検証：ログイン後は認証誘導画面へ遷移する
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('verification.notice'));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_unverified_user_cannot_view_or_submit_attendance(): void
    {
        // 準備：メール未認証のユーザーでログインする
        $user = User::factory()->unverified()->create([
            'admin_status' => false,
        ]);

        $this->actingAs($user, 'web');

        // 実行・検証：勤怠登録画面と打刻処理の両方が制限される
        $this->get('/attendance')
            ->assertRedirect(route('verification.notice'));

        $this->post('/attendance', [
            'action' => 'clock_in',
        ])->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_verified_user_can_log_in_without_verifying_again(): void
    {
        // 準備：Factory標準のメール認証済みユーザー
        $user = User::factory()->create([
            'email' => 'verified@example.com',
            'admin_status' => false,
        ]);

        // 実行：認証済みユーザーがログインする
        $response = $this->post('/login', [
            'email' => 'verified@example.com',
            'password' => 'password',
        ]);

        // 検証：再認証を求めず勤怠登録画面へ遷移する
        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/attendance');

        $this->assertAuthenticatedAs($user, 'web');
        $this->get('/attendance')->assertOk();
    }

    public function test_tampered_verification_url_is_rejected(): void
    {
        // 準備：有効な認証URLのクエリを改ざんする
        $user = User::factory()->unverified()->create([
            'admin_status' => false,
        ]);

        $verificationUrl = $this->verificationUrl($user).'&tampered=1';

        // 実行・検証：改ざんしたリンクでは認証できない
        $this->actingAs($user, 'web')
            ->get($verificationUrl)
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    /**
     * メール認証用の有効期限付き署名URLを作成する。
     */
    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );
    }
}
