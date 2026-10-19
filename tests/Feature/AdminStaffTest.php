<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_general_users_are_displayed_in_staff_list(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create([
            'name' => '管理者1',
            'email' => 'admin1@example.com',
            'admin_status' => true,
        ]);

        User::factory()->create([
            'name' => '管理者2',
            'email' => 'admin2@example.com',
            'admin_status' => true,
        ]);

        $user1 = User::factory()->create([
            'name' => '一般ユーザー1',
            'email' => 'user1@example.com',
            'admin_status' => false,
        ]);

        $user2 = User::factory()->create([
            'name' => '一般ユーザー2',
            'email' => 'user2@example.com',
            'admin_status' => false,
        ]);

        $response = $this->actingAs($admin)->get('/admin/staff/list');

        $response->assertOk();
        $response->assertViewIs('admin.staff-list');

        // 一般ユーザー2名だけが画面に渡されている
        $response->assertViewHas(
            'users',
            fn ($users): bool => $users->modelKeys() === [$user1->id, $user2->id]
        );

        // 表の氏名・メールアドレスを行ごとに確認する
        $document = new \DOMDocument;
        $document->loadHTML(
            '<?xml encoding="UTF-8"?>'.$response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new \DOMXPath($document);
        $actualRows = [];

        foreach ($xpath->query('//table//tr[td]') as $row) {
            $cells = [];

            foreach ($xpath->query('./td', $row) as $cell) {
                $cells[] = trim($cell->textContent);
            }

            $actualRows[] = $cells;
        }

        $this->assertSame([
            ['一般ユーザー1', 'user1@example.com', '詳細'],
            ['一般ユーザー2', 'user2@example.com', '詳細'],
        ], $actualRows);

        // 各ユーザーの月次勤怠リンクも確認する
        $response->assertSee(
            'href="'.url('/admin/attendance/staff/'.$user1->id).'"',
            false
        );
        $response->assertSee(
            'href="'.url('/admin/attendance/staff/'.$user2->id).'"',
            false
        );
    }

    public function test_selected_staff_attendance_is_displayed(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        $user = User::factory()->create([
            'name' => '対象ユーザー',
            'admin_status' => false,
        ]);

        $otherUser = User::factory()->create([
            'admin_status' => false,
        ]);

        $cases = [
            ['2026-10-01', '09:05:00', '18:05:00', '12:00:00', '13:00:00'],
            ['2026-10-15', '10:15:00', '19:45:00', '13:00:00', '13:30:00'],
        ];

        $expectedIds = [];

        foreach ($cases as [
            $date, $clockIn, $clockOut, $breakIn, $breakOut
        ]) {
            $record = $user->attendanceRecords()->create([
                'date' => $date,
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
            ]);

            $record->breaks()->create([
                'break_in' => $breakIn,
                'break_out' => $breakOut,
            ]);

            $expectedIds[] = $record->id;
        }

        // 同じ月の別ユーザーの勤怠
        $otherUser->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '06:45:00',
            'clock_out' => '15:45:00',
        ]);

        // 対象ユーザーの別月の勤怠
        $user->attendanceRecords()->create([
            'date' => '2026-09-30',
            'clock_in' => '07:35:00',
            'clock_out' => '16:35:00',
        ]);

        $response = $this->actingAs($admin)->get(
            '/admin/attendance/staff/'.$user->id.'?date=2026-10'
        );

        $response->assertOk();
        $response->assertViewIs('admin.staff-attendance-list');
        $response->assertSeeText('対象ユーザーさんの勤怠');
        $response->assertSee(
            '<p class="current-month">2026/10</p>',
            false
        );

        $response->assertViewHas(
            'user',
            fn (User $viewUser): bool => $viewUser->id === $user->id
        );

        $response->assertViewHas(
            'formattedAttendanceRecords',
            fn (array $records): bool => collect($records)
                ->whereNotNull('id')
                ->pluck('id')
                ->values()
                ->all() === $expectedIds
        );

        $document = new \DOMDocument;
        $document->loadHTML(
            '<?xml encoding="UTF-8"?>'.$response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new \DOMXPath($document);
        $actualRows = [];

        // 詳細リンクがある、勤怠登録済みの行を確認する
        foreach ($xpath->query('//table//tr[td]') as $row) {
            if ($xpath->query('.//a', $row)->length === 0) {
                continue;
            }

            $cells = [];

            foreach ($xpath->query('./td', $row) as $cell) {
                $cells[] = trim($cell->textContent);
            }

            $actualRows[] = $cells;
        }

        $this->assertSame([
            ['10/01(木)', '09:05', '18:05', '1:00', '8:00', '詳細'],
            ['10/15(木)', '10:15', '19:45', '0:30', '9:00', '詳細'],
        ], $actualRows);

        $response->assertDontSeeText('06:45');
        $response->assertDontSeeText('15:45');
        $response->assertDontSeeText('07:35');
        $response->assertDontSeeText('16:35');
    }

    public function test_previous_month_staff_attendance_is_displayed(): void
    {
        $this->withoutVite();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-10-19 20:00:00', 'Asia/Tokyo')
        );

        try {
            $admin = User::factory()->create([
                'admin_status' => true,
            ]);

            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            $previousRecord = $user->attendanceRecords()->create([
                'date' => '2026-09-10',
                'clock_in' => '08:45:00',
                'clock_out' => '17:45:00',
            ]);

            $user->attendanceRecords()->create([
                'date' => '2026-10-19',
                'clock_in' => '09:15:00',
                'clock_out' => '18:15:00',
            ]);

            $this->actingAs($admin);

            $listPath = '/admin/attendance/staff/'.$user->id;

            $this->get($listPath)
                ->assertOk()
                ->assertSee(
                    '<a class="previous-month" href="?date=2026-09">前月</a>',
                    false
                );

            $response = $this->get($listPath.'?date=2026-09');

            $response->assertOk();
            $response->assertSee(
                '<p class="current-month">2026/09</p>',
                false
            );

            // 対象スタッフが維持され、前月の勤怠だけが表示される
            $response->assertViewHas(
                'user',
                fn (User $viewUser): bool => $viewUser->id === $user->id
            );

            $response->assertViewHas(
                'formattedAttendanceRecords',
                fn (array $records): bool => collect($records)
                    ->whereNotNull('id')
                    ->pluck('id')
                    ->values()
                    ->all() === [$previousRecord->id]
            );

            $response->assertSeeText('08:45');
            $response->assertSeeText('17:45');
            $response->assertDontSeeText('09:15');
            $response->assertDontSeeText('18:15');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_next_month_staff_attendance_is_displayed(): void
    {
        $this->withoutVite();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-10-19 20:00:00', 'Asia/Tokyo')
        );

        try {
            $admin = User::factory()->create([
                'admin_status' => true,
            ]);

            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            $user->attendanceRecords()->create([
                'date' => '2026-10-19',
                'clock_in' => '09:15:00',
                'clock_out' => '18:15:00',
            ]);

            $nextRecord = $user->attendanceRecords()->create([
                'date' => '2026-11-10',
                'clock_in' => '08:45:00',
                'clock_out' => '17:45:00',
            ]);

            $this->actingAs($admin);

            $listPath = '/admin/attendance/staff/'.$user->id;

            $this->get($listPath)
                ->assertOk()
                ->assertSee(
                    '<a class="next-month" href="?date=2026-11">翌月</a>',
                    false
                );

            $response = $this->get($listPath.'?date=2026-11');

            $response->assertOk();
            $response->assertSee(
                '<p class="current-month">2026/11</p>',
                false
            );

            // 対象スタッフが維持され、翌月の勤怠だけが表示される
            $response->assertViewHas(
                'user',
                fn (User $viewUser): bool => $viewUser->id === $user->id
            );

            $response->assertViewHas(
                'formattedAttendanceRecords',
                fn (array $records): bool => collect($records)
                    ->whereNotNull('id')
                    ->pluck('id')
                    ->values()
                    ->all() === [$nextRecord->id]
            );

            $response->assertSeeText('08:45');
            $response->assertSeeText('17:45');
            $response->assertDontSeeText('09:15');
            $response->assertDontSeeText('18:15');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_staff_detail_link_opens_selected_attendance(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        $user = User::factory()->create([
            'name' => '対象スタッフ',
            'admin_status' => false,
        ]);

        $user->attendanceRecords()->create([
            'date' => '2026-10-01',
            'clock_in' => '09:05:00',
            'clock_out' => '18:05:00',
        ]);

        $targetRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '10:15:00',
            'clock_out' => '19:15:00',
        ]);

        $this->actingAs($admin);

        $linkPath = '/attendance/'.$targetRecord->id;
        $detailPath = '/admin/attendance/'.$targetRecord->id;

        // スタッフ月次一覧に対象の詳細リンクがある
        $this->get(
            '/admin/attendance/staff/'.$user->id.'?date=2026-10'
        )
            ->assertOk()
            ->assertSee('href="'.url($linkPath).'"', false);

        // 互換ルートから管理者用詳細へ遷移する
        $this->get($linkPath)->assertRedirect($detailPath);

        $response = $this->get($detailPath);

        $response->assertOk();
        $response->assertViewIs('admin.admin-detail');

        $response->assertViewHas(
            'user',
            fn (User $viewUser): bool => $viewUser->id === $user->id
        );

        $response->assertViewHas(
            'attendanceRecord',
            fn (array $data): bool => $data['id'] === $targetRecord->id
                && $data['year'] === '2026年'
                && $data['date'] === '10月15日'
                && $data['clock_in'] === '10:15'
                && $data['clock_out'] === '19:15'
        );

        $response->assertSee(
            'name="name" value="対象スタッフ"',
            false
        );
        $response->assertSee(
            'name="new_date" value="10月15日"',
            false
        );
        $response->assertSee('value="10:15"', false);
        $response->assertSee('value="19:15"', false);
    }
}
