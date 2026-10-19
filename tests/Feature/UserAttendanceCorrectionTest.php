<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_clock_in_after_clock_out_is_rejected(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $attendanceRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $detailPath = '/attendance/detail/'.$attendanceRecord->id;

        $this->actingAs($user);

        // 出勤19時・退勤18時で修正申請する
        $response = $this->from($detailPath)->post($detailPath, [
            'new_clock_in' => '19:00',
            'new_clock_out' => '18:00',
            'comment' => '出勤時刻の修正',
        ]);

        $response->assertRedirect($detailPath);
        $response->assertSessionHasErrors([
            'new_clock_out' => '出勤時間もしくは退勤時間が不適切な値です',
        ]);

        $this->get($detailPath)
            ->assertOk()
            ->assertSeeText('出勤時間もしくは退勤時間が不適切な値です');

        // 修正申請が作成されていない
        $this->assertSame(0, $attendanceRecord->applications()->count());

        // 元の勤怠情報が維持されている
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

    }

    public function test_break_start_after_clock_out_is_rejected(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $attendanceRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $attendanceRecord->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $detailPath = '/attendance/detail/'.$attendanceRecord->id;

        $this->actingAs($user);

        $response = $this->from($detailPath)->post($detailPath, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['19:00'],
            'new_break_out' => ['19:30'],
            'comment' => '休憩時刻の修正',
        ]);

        $response->assertRedirect($detailPath);
        $response->assertSessionHasErrors([
            'new_break_in.0' => '休憩時間が不適切な値です',
        ]);

        $this->get($detailPath)
            ->assertOk()
            ->assertSeeText('休憩時間が不適切な値です');

        $this->assertSame(0, $attendanceRecord->applications()->count());

        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        // 元の休憩記録も維持されている
        $this->assertSame(1, $attendanceRecord->breaks()->count());

        $breakRecord = $attendanceRecord->breaks()->firstOrFail();

        $this->assertSame('12:00:00', $breakRecord->break_in);
        $this->assertSame('13:00:00', $breakRecord->break_out);
    }

    public function test_break_end_after_clock_out_is_rejected(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $attendanceRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $attendanceRecord->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $detailPath = '/attendance/detail/'.$attendanceRecord->id;

        $this->actingAs($user);

        $response = $this->from($detailPath)->post($detailPath, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['17:00'],
            'new_break_out' => ['18:30'],
            'comment' => '休憩時刻の修正',
        ]);

        $response->assertRedirect($detailPath);
        $response->assertSessionHasErrors([
            'new_break_out.0' => '休憩時間もしくは退勤時間が不適切な値です',
        ]);

        $this->get($detailPath)
            ->assertOk()
            ->assertSeeText('休憩時間もしくは退勤時間が不適切な値です');

        $this->assertSame(0, $attendanceRecord->applications()->count());

        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        // 元の休憩記録も維持されている
        $this->assertSame(1, $attendanceRecord->breaks()->count());

        $breakRecord = $attendanceRecord->breaks()->firstOrFail();

        $this->assertSame('12:00:00', $breakRecord->break_in);
        $this->assertSame('13:00:00', $breakRecord->break_out);
    }

    public function test_comment_is_required(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $attendanceRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $attendanceRecord->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $detailPath = '/attendance/detail/'.$attendanceRecord->id;

        $this->actingAs($user);

        $response = $this->from($detailPath)->post($detailPath, [
            'new_clock_in' => '09:15',
            'new_clock_out' => '18:15',
            'new_break_in' => ['12:15'],
            'new_break_out' => ['13:15'],
            'comment' => '',
        ]);

        $response->assertRedirect($detailPath);
        $response->assertSessionHasErrors([
            'comment' => '備考を記入してください',
        ]);

        $this->get($detailPath)
            ->assertOk()
            ->assertSeeText('備考を記入してください');

        $this->assertSame(0, $attendanceRecord->applications()->count());

        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        // 元の休憩記録も維持されている
        $this->assertSame(1, $attendanceRecord->breaks()->count());

        $breakRecord = $attendanceRecord->breaks()->firstOrFail();

        $this->assertSame('12:00:00', $breakRecord->break_in);
        $this->assertSame('13:00:00', $breakRecord->break_out);
    }

    public function test_correction_request_is_saved_and_visible_to_admin(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'name' => '申請テストユーザー',
            'admin_status' => false,
        ]);

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        $attendanceRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $originalBreak = $attendanceRecord->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $detailPath = '/attendance/detail/'.$attendanceRecord->id;

        // 一般ユーザーが正常な修正申請を送信する
        $this->actingAs($user)
            ->from($detailPath)
            ->post($detailPath, [
                'new_clock_in' => '09:15',
                'new_clock_out' => '18:15',
                'new_break_in' => ['12:15'],
                'new_break_out' => ['13:15'],
                'comment' => '打刻漏れのため修正',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($detailPath);

        // 申請が1件保存され、内容が正しい
        $this->assertSame(1, $attendanceRecord->applications()->count());

        $application = $attendanceRecord->applications()->firstOrFail();

        $this->assertSame($user->id, $application->user_id);
        $this->assertSame(
            $attendanceRecord->id,
            $application->attendance_record_id
        );
        $this->assertSame(
            '2026-10-15',
            $application->new_date->format('Y-m-d')
        );
        $this->assertSame('09:15:00', $application->new_clock_in);
        $this->assertSame('18:15:00', $application->new_clock_out);
        $this->assertSame('打刻漏れのため修正', $application->comment);
        $this->assertSame('承認待ち', $application->approval_status);

        // 修正後の休憩時刻も申請に保存されている
        $this->assertSame(1, $application->proposalBreaks()->count());

        $proposalBreak = $application->proposalBreaks()->firstOrFail();

        $this->assertSame('12:15:00', $proposalBreak->break_in);
        $this->assertSame('13:15:00', $proposalBreak->break_out);

        // 承認前の元の勤怠・休憩は維持されている
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $originalBreak->refresh();

        $this->assertSame('12:00:00', $originalBreak->break_in);
        $this->assertSame('13:00:00', $originalBreak->break_out);

        // 管理者として申請一覧を開く
        $this->actingAs($admin);

        $approvalPath = '/stamp_correction_request/approve/'
            .$application->id;

        $listResponse = $this->get('/stamp_correction_request/list');

        $listResponse->assertOk();
        $listResponse->assertViewIs('admin.admin-application-list');
        $listResponse->assertSeeText('申請テストユーザー');
        $listResponse->assertSeeText('2026/10/15');
        $listResponse->assertSeeText('打刻漏れのため修正');
        $listResponse->assertSee(
            'href="'.url($approvalPath).'"',
            false
        );

        // 申請IDに対応する承認画面を開く
        $detailResponse = $this->get($approvalPath);

        $detailResponse->assertOk();
        $detailResponse->assertViewIs('admin.admin-application-detail');
        $detailResponse->assertViewHas(
            'application',
            fn ($viewApplication): bool => $viewApplication->id === $application->id
                && $viewApplication->attendance_record_id
                    === $attendanceRecord->id
        );

        $detailResponse->assertSee(
            'name="name" value="申請テストユーザー"',
            false
        );
        $detailResponse->assertSee('value="09:15"', false);
        $detailResponse->assertSee('value="18:15"', false);
        $detailResponse->assertSee('value="12:15"', false);
        $detailResponse->assertSee('value="13:15"', false);
        $detailResponse->assertSeeText('打刻漏れのため修正');
        $detailResponse->assertSee(
            'type="submit">承認</button>',
            false
        );
    }

    public function test_pending_tab_shows_all_own_pending_applications(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $otherUser = User::factory()->create([
            'admin_status' => false,
        ]);

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        // 申請者・対象日・申請理由・承認するか
        $cases = [
            [$user, '2026-10-13', '本人の申請1', false],
            [$user, '2026-10-14', '本人の申請2', false],
            [$user, '2026-10-15', '本人の承認済み申請', true],
            [$otherUser, '2026-10-16', '別ユーザーの申請', false],
        ];

        $applications = [];

        foreach ($cases as [$owner, $date, $comment, $approve]) {
            $attendanceRecord = $owner->attendanceRecords()->create([
                'date' => $date,
                'clock_in' => '09:00:00',
                'clock_out' => '18:00:00',
            ]);

            $detailPath = '/attendance/detail/'.$attendanceRecord->id;

            // 各ユーザーとして修正申請する
            $this->actingAs($owner)
                ->post($detailPath, [
                    'new_clock_in' => '09:15',
                    'new_clock_out' => '18:15',
                    'comment' => $comment,
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect($detailPath);

            $application = $attendanceRecord->applications()
                ->firstOrFail();

            $applications[$comment] = $application;

            // 比較用の承認済み申請を用意する
            if ($approve) {
                $approvalPath = '/stamp_correction_request/approve/'
                    .$application->id;

                $this->actingAs($admin)
                    ->post($approvalPath)
                    ->assertSessionHasNoErrors()
                    ->assertRedirect($approvalPath);
            }
        }

        // 本人として申請一覧を開く
        $response = $this->actingAs($user)
            ->get('/stamp_correction_request/list');

        $response->assertOk();
        $response->assertViewIs('user.user-application-list');
        $response->assertSeeText('本人の申請1');
        $response->assertSeeText('本人の申請2');
        $response->assertDontSeeText('別ユーザーの申請');

        // 承認待ちタブだけを取り出す
        $document = new \DOMDocument;
        $document->loadHTML(
            $response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new \DOMXPath($document);

        $this->assertSame(
            1,
            $xpath->query('//*[@id="content1"]')->length
        );

        $actualLinks = [];

        foreach ($xpath->query('//*[@id="content1"]//a') as $link) {
            $actualLinks[] = $link->getAttribute('href');
        }

        // 本人の承認待ち2件だけがあり、漏れも混入もない
        $this->assertEqualsCanonicalizing([
            url('/application/'.$applications['本人の申請1']->id),
            url('/application/'.$applications['本人の申請2']->id),
        ], $actualLinks);
    }

    public function test_approved_tab_shows_all_own_approved_applications(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $otherUser = User::factory()->create([
            'admin_status' => false,
        ]);

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        // 申請者・対象日・申請理由・承認するか
        $cases = [
            [$user, '2026-10-13', '本人の承認済み申請1', true],
            [$user, '2026-10-14', '本人の承認済み申請2', true],
            [$user, '2026-10-15', '本人の承認待ち申請', false],
            [$otherUser, '2026-10-16', '別ユーザーの承認済み申請', true],
        ];

        $applications = [];

        foreach ($cases as [$owner, $date, $comment, $approve]) {
            $attendanceRecord = $owner->attendanceRecords()->create([
                'date' => $date,
                'clock_in' => '09:00:00',
                'clock_out' => '18:00:00',
            ]);

            $detailPath = '/attendance/detail/'.$attendanceRecord->id;

            $this->actingAs($owner)
                ->post($detailPath, [
                    'new_clock_in' => '09:15',
                    'new_clock_out' => '18:15',
                    'comment' => $comment,
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect($detailPath);

            $application = $attendanceRecord->applications()
                ->firstOrFail();

            $applications[$comment] = $application;

            if ($approve) {
                $approvalPath = '/stamp_correction_request/approve/'
                    .$application->id;

                // 管理者が実際に承認する
                $this->actingAs($admin)
                    ->post($approvalPath)
                    ->assertSessionHasNoErrors()
                    ->assertRedirect($approvalPath);

                $application->refresh();

                $this->assertSame(
                    '承認済み',
                    $application->approval_status
                );
            }
        }

        // 本人として申請一覧を開く
        $response = $this->actingAs($user)
            ->get('/stamp_correction_request/list');

        $response->assertOk();
        $response->assertViewIs('user.user-application-list');
        $response->assertSeeText('本人の承認済み申請1');
        $response->assertSeeText('本人の承認済み申請2');
        $response->assertDontSeeText('別ユーザーの承認済み申請');

        // 承認済みタブだけを取り出す
        $document = new \DOMDocument;
        $document->loadHTML(
            $response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new \DOMXPath($document);

        $this->assertSame(
            1,
            $xpath->query('//*[@id="content2"]')->length
        );

        $actualLinks = [];

        foreach ($xpath->query('//*[@id="content2"]//a') as $link) {
            $actualLinks[] = $link->getAttribute('href');
        }

        // 本人の承認済み2件だけがあり、漏れも混入もない
        $this->assertEqualsCanonicalizing([
            url('/application/'.$applications['本人の承認済み申請1']->id),
            url('/application/'.$applications['本人の承認済み申請2']->id),
        ], $actualLinks);
    }

    public function test_application_links_open_correct_attendance_details(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        // 申請のない勤怠を先に作り、勤怠IDと申請IDをずらす
        $user->attendanceRecords()->create([
            'date' => '2026-10-12',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        $cases = [
            ['2026-10-13', '10月13日', '承認待ちの詳細確認', false],
            ['2026-10-14', '10月14日', '承認済みの詳細確認', true],
        ];

        $targets = [];

        foreach ($cases as [$date, $displayDate, $comment, $approve]) {
            $attendanceRecord = $user->attendanceRecords()->create([
                'date' => $date,
                'clock_in' => '09:00:00',
                'clock_out' => '18:00:00',
            ]);

            $detailPath = '/attendance/detail/'.$attendanceRecord->id;

            $this->actingAs($user)
                ->post($detailPath, [
                    'new_clock_in' => '09:15',
                    'new_clock_out' => '18:15',
                    'comment' => $comment,
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect($detailPath);

            $application = $attendanceRecord->applications()
                ->firstOrFail();

            if ($approve) {
                $approvalPath = '/stamp_correction_request/approve/'
                    .$application->id;

                $this->actingAs($admin)
                    ->post($approvalPath)
                    ->assertSessionHasNoErrors()
                    ->assertRedirect($approvalPath);
            }

            $targets[] = [
                'record' => $attendanceRecord,
                'application' => $application,
                'date' => $displayDate,
                'comment' => $comment,
            ];
        }

        $this->actingAs($user);

        $listResponse = $this->get('/stamp_correction_request/list');
        $listResponse->assertOk();

        foreach ($targets as $target) {
            $applicationPath = '/application/'
                .$target['application']->id;

            $detailPath = '/attendance/detail/'
                .$target['record']->id;

            // 一覧に申請IDを使った詳細リンクがある
            $listResponse->assertSee(
                'href="'.url($applicationPath).'"',
                false
            );

            // 申請から対応する勤怠詳細へリダイレクトされる
            $this->get($applicationPath)
                ->assertRedirect($detailPath);

            $response = $this->get($detailPath);

            $response->assertOk();
            $response->assertViewIs('user.user-detail');

            // 選択した申請に対応する勤怠と内容が表示される
            $response->assertViewHas(
                'data',
                fn (array $data): bool => $data['id'] === $target['record']->id
                    && $data['date'] === $target['date']
                    && $data['comment'] === $target['comment']
                    && $data['clock_in'] === '09:15'
                    && $data['clock_out'] === '18:15'
            );

            $response->assertSee(
                'value="'.$target['date'].'"',
                false
            );
            $response->assertSee(
                'name="comment" value="'.$target['comment'].'"',
                false
            );
        }
    }
}
