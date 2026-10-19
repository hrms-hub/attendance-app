<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCorrectionRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_tab_shows_all_users_pending_applications(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        $user1 = User::factory()->create([
            'name' => '一般ユーザー1',
            'admin_status' => false,
        ]);

        $user2 = User::factory()->create([
            'name' => '一般ユーザー2',
            'admin_status' => false,
        ]);

        $cases = [
            [$user1, '2026-10-13', 'ユーザー1の未承認申請', false],
            [$user2, '2026-10-14', 'ユーザー2の未承認申請', false],
            [$user1, '2026-10-15', '承認済みの比較用申請', true],
        ];

        $expectedRows = [];

        foreach ($cases as [$owner, $date, $comment, $approve]) {
            $record = $owner->attendanceRecords()->create([
                'date' => $date,
                'clock_in' => '09:00:00',
                'clock_out' => '18:00:00',
            ]);

            $detailPath = '/attendance/detail/'.$record->id;

            $this->actingAs($owner)
                ->post($detailPath, [
                    'new_clock_in' => '09:15',
                    'new_clock_out' => '18:15',
                    'comment' => $comment,
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect($detailPath);

            $application = $record->applications()->firstOrFail();

            if ($approve) {
                $approvalPath = '/stamp_correction_request/approve/'
                    .$application->id;

                $this->actingAs($admin)
                    ->post($approvalPath)
                    ->assertSessionHasNoErrors()
                    ->assertRedirect($approvalPath);
            } else {
                $expectedRows[] = [
                    '承認待ち',
                    $owner->name,
                    str_replace('-', '/', $date),
                    $comment,
                ];
            }
        }

        $response = $this->actingAs($admin)
            ->get('/stamp_correction_request/list');

        $response->assertOk();
        $response->assertViewIs('admin.admin-application-list');

        $document = new \DOMDocument;
        $document->loadHTML(
            '<?xml encoding="UTF-8"?>'.$response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new \DOMXPath($document);

        $this->assertSame(
            1,
            $xpath->query('//*[@id="content1"]')->length
        );

        $actualRows = [];

        // 承認待ちタブの状態・名前・対象日・申請理由を確認する
        foreach ($xpath->query('//*[@id="content1"]//tr[td]') as $row) {
            $cells = [];

            foreach ($xpath->query('./td', $row) as $cell) {
                $cells[] = trim($cell->textContent);
            }

            $actualRows[] = array_slice($cells, 0, 4);
        }

        // 表示順に依存せず、全件の内容と件数を照合する
        sort($expectedRows);
        sort($actualRows);

        $this->assertSame($expectedRows, $actualRows);
    }

    public function test_approved_tab_shows_all_users_approved_applications(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        $user1 = User::factory()->create([
            'name' => '一般ユーザー1',
            'admin_status' => false,
        ]);

        $user2 = User::factory()->create([
            'name' => '一般ユーザー2',
            'admin_status' => false,
        ]);

        $cases = [
            [$user1, '2026-10-13', 'ユーザー1の承認済み申請', true],
            [$user2, '2026-10-14', 'ユーザー2の承認済み申請', true],
            [$user1, '2026-10-15', '承認待ちの比較用申請', false],
        ];

        $expectedRows = [];

        foreach ($cases as [$owner, $date, $comment, $approve]) {
            $record = $owner->attendanceRecords()->create([
                'date' => $date,
                'clock_in' => '09:00:00',
                'clock_out' => '18:00:00',
            ]);

            $detailPath = '/attendance/detail/'.$record->id;

            $this->actingAs($owner)
                ->post($detailPath, [
                    'new_clock_in' => '09:15',
                    'new_clock_out' => '18:15',
                    'comment' => $comment,
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect($detailPath);

            $application = $record->applications()->firstOrFail();

            if ($approve) {
                $approvalPath = '/stamp_correction_request/approve/'
                    .$application->id;

                $this->actingAs($admin)
                    ->post($approvalPath)
                    ->assertSessionHasNoErrors()
                    ->assertRedirect($approvalPath);

                $application->refresh();

                $this->assertSame(
                    '承認済み',
                    $application->approval_status
                );

                $expectedRows[] = [
                    '承認済み',
                    $owner->name,
                    str_replace('-', '/', $date),
                    $comment,
                ];
            }
        }

        $response = $this->actingAs($admin)
            ->get('/stamp_correction_request/list');

        $response->assertOk();
        $response->assertViewIs('admin.admin-application-list');

        $document = new \DOMDocument;
        $document->loadHTML(
            '<?xml encoding="UTF-8"?>'.$response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new \DOMXPath($document);

        $this->assertSame(
            1,
            $xpath->query('//*[@id="content2"]')->length
        );

        $actualRows = [];

        // 承認済みタブの状態・名前・対象日・申請理由を確認する
        foreach ($xpath->query('//*[@id="content2"]//tr[td]') as $row) {
            $cells = [];

            foreach ($xpath->query('./td', $row) as $cell) {
                $cells[] = trim($cell->textContent);
            }

            $actualRows[] = array_slice($cells, 0, 4);
        }

        sort($expectedRows);
        sort($actualRows);

        $this->assertSame($expectedRows, $actualRows);
    }

    public function test_selected_application_details_are_displayed(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        $user = User::factory()->create([
            'name' => '申請対象ユーザー',
            'admin_status' => false,
        ]);

        // 勤怠IDと申請IDをずらしておく
        $user->attendanceRecords()->create([
            'date' => '2026-10-12',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        $record = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $record->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $detailPath = '/attendance/detail/'.$record->id;

        $this->actingAs($user)
            ->post($detailPath, [
                'new_clock_in' => '09:15',
                'new_clock_out' => '18:30',
                'new_break_in' => ['12:15', '15:00'],
                'new_break_out' => ['13:00', '15:20'],
                'comment' => '確認対象の修正理由',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($detailPath);

        $application = $record->applications()->firstOrFail();

        $response = $this->actingAs($admin)->get(
            '/stamp_correction_request/approve/'.$application->id
        );

        $response->assertOk();
        $response->assertViewIs('admin.admin-application-detail');

        $response->assertViewHas(
            'user',
            fn (User $viewUser): bool => $viewUser->id === $user->id
        );

        $response->assertViewHas(
            'application',
            fn ($viewApplication): bool => $viewApplication->id === $application->id
                && $viewApplication->attendance_record_id === $record->id
                && $viewApplication->approval_status === '承認待ち'
        );

        $response->assertSee(
            'name="name" value="申請対象ユーザー"',
            false
        );
        $response->assertSee('value="2026年"', false);
        $response->assertSee('value="10月15日"', false);
        $response->assertSee('value="09:15"', false);
        $response->assertSee('value="18:30"', false);

        $document = new \DOMDocument;
        $document->loadHTML(
            '<?xml encoding="UTF-8"?>'.$response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new \DOMXPath($document);

        // 申請した2回分の休憩が正しい順序で表示される
        $expectedBreaks = [
            'new_break_in[]' => ['12:15', '15:00'],
            'new_break_out[]' => ['13:00', '15:20'],
        ];

        foreach ($expectedBreaks as $name => $expectedValues) {
            $actualValues = [];

            foreach ($xpath->query('//input[@name="'.$name.'"]') as $input) {
                $actualValues[] = $input->getAttribute('value');
            }

            $this->assertSame($expectedValues, $actualValues, $name);
        }

        $comments = $xpath->query('//textarea[@name="comment"]');

        $this->assertSame(1, $comments->length);
        $this->assertSame(
            '確認対象の修正理由',
            $comments->item(0)->textContent
        );

        $response->assertSee(
            'type="submit">承認</button>',
            false
        );
    }

    public function test_approval_updates_attendance_and_application(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        // 対象外の勤怠を先に作り、勤怠IDと申請IDをずらす
        $otherRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-12',
            'clock_in' => '08:00:00',
            'clock_out' => '17:00:00',
            'comment' => '対象外の勤怠',
        ]);

        $record = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $record->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $detailPath = '/attendance/detail/'.$record->id;

        $this->actingAs($user)
            ->post($detailPath, [
                'new_clock_in' => '09:15',
                'new_clock_out' => '18:30',
                'new_break_in' => ['12:15', '15:00'],
                'new_break_out' => ['13:00', '15:20'],
                'comment' => '承認対象の修正理由',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect($detailPath);

        $application = $record->applications()->firstOrFail();

        // 承認前は元の勤怠が維持されている
        $this->assertSame('承認待ち', $application->approval_status);

        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        // 管理者が承認する
        $approvalPath = '/stamp_correction_request/approve/'
            .$application->id;

        $this->actingAs($admin)
            ->post($approvalPath)
            ->assertSessionHasNoErrors()
            ->assertRedirect($approvalPath);

        $application->refresh();

        $this->assertSame('承認済み', $application->approval_status);
        $this->assertSame($admin->id, $application->approved_by);
        $this->assertNotNull($application->approved_at);

        // 対象の勤怠に修正内容が反映されている
        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'user_id' => $user->id,
            'date' => '2026-10-15',
            'clock_in' => '09:15:00',
            'clock_out' => '18:30:00',
            'comment' => '承認対象の修正理由',
        ]);

        // 休憩も申請した2件に更新されている
        $actualBreaks = $record->breaks()
            ->orderBy('id')
            ->get()
            ->map(fn ($break): array => [
                $break->break_in,
                $break->break_out,
            ])
            ->all();

        $this->assertSame([
            ['12:15:00', '13:00:00'],
            ['15:00:00', '15:20:00'],
        ], $actualBreaks);

        // 対象外の勤怠は変わっていない
        $this->assertDatabaseHas('attendance_records', [
            'id' => $otherRecord->id,
            'user_id' => $user->id,
            'date' => '2026-10-12',
            'clock_in' => '08:00:00',
            'clock_out' => '17:00:00',
            'comment' => '対象外の勤怠',
        ]);

        // 承認画面が承認済みの表示に変わる
        $this->get($approvalPath)
            ->assertOk()
            ->assertSeeText('承認済み')
            ->assertDontSee('type="submit">承認</button>', false);
    }
}
