<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAttendanceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_users_daily_attendance_is_displayed(): void
    {
        $this->withoutVite();

        Carbon::setTestNow(
            Carbon::parse('2026-10-19 20:00:00', 'Asia/Tokyo')
        );

        try {
            $user1 = User::factory()->create([
                'name' => '一般ユーザー1',
                'admin_status' => false,
            ]);

            $user2 = User::factory()->create([
                'name' => '一般ユーザー2',
                'admin_status' => false,
            ]);

            $admin = User::factory()->create([
                'name' => '管理者ユーザー',
                'admin_status' => true,
            ]);

            // ユーザー・出勤・退勤・休憩開始・休憩終了・休憩合計・勤務合計
            $cases = [
                [$user1, '09:00:00', '18:00:00', '12:00:00', '13:00:00', '1:00', '8:00'],
                [$user2, '10:15:00', '19:45:00', '13:00:00', '13:30:00', '0:30', '9:00'],
                [$admin, '08:30:00', '17:00:00', '12:00:00', '12:45:00', '0:45', '7:45'],
            ];

            $expectedIds = [];
            $expectedRows = [];

            foreach ($cases as [
                $owner, $clockIn, $clockOut,
                $breakIn, $breakOut, $breakTotal, $workTotal
            ]) {
                $record = $owner->attendanceRecords()->create([
                    'date' => '2026-10-19',
                    'clock_in' => $clockIn,
                    'clock_out' => $clockOut,
                ]);

                $record->breaks()->create([
                    'break_in' => $breakIn,
                    'break_out' => $breakOut,
                ]);

                $expectedIds[] = $record->id;

                $expectedRows[] = [
                    $owner->name,
                    substr($clockIn, 0, 5),
                    substr($clockOut, 0, 5),
                    $breakTotal,
                    $workTotal,
                    '詳細',
                ];
            }

            // 前日の記録は表示対象にならない
            $user1->attendanceRecords()->create([
                'date' => '2026-10-18',
                'clock_in' => '06:05:00',
                'clock_out' => '15:05:00',
            ]);

            $response = $this->actingAs($admin)
                ->get('/admin/attendance/list');

            $response->assertOk();
            $response->assertViewIs('admin.admin-attendance-list');

            // 当日の3件だけが画面に渡されている
            $response->assertViewHas(
                'attendanceRecords',
                fn ($records): bool => collect($records->modelKeys())
                    ->sort()
                    ->values()
                    ->all() === $expectedIds
            );

            // 表の各行・各列の表示内容を確認する
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

            $this->assertSame($expectedRows, $actualRows);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_current_date_is_displayed_by_default(): void
    {
        $this->withoutVite();

        Carbon::setTestNow(
            Carbon::parse('2026-10-19 10:00:00', 'Asia/Tokyo')
        );

        try {
            $admin = User::factory()->create([
                'admin_status' => true,
            ]);

            // 日付を指定せずに一覧を開く
            $response = $this->actingAs($admin)
                ->get('/admin/attendance/list');

            $response->assertOk();

            $response->assertViewHas(
                'date',
                fn (Carbon $date): bool => $date->format('Y-m-d') === '2026-10-19'
            );

            // 見出しと日付欄が現在の日付になっている
            $response->assertSeeText('2026年10月19日の勤怠');
            $response->assertSee(
                '<p class="current-day">2026/10/19</p>',
                false
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_previous_day_attendance_is_displayed(): void
    {
        $this->withoutVite();

        Carbon::setTestNow(
            Carbon::parse('2026-10-19 20:00:00', 'Asia/Tokyo')
        );

        try {
            $admin = User::factory()->create([
                'admin_status' => true,
            ]);

            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            $previousRecord = $user->attendanceRecords()->create([
                'date' => '2026-10-18',
                'clock_in' => '08:45:00',
                'clock_out' => '17:45:00',
            ]);

            $user->attendanceRecords()->create([
                'date' => '2026-10-19',
                'clock_in' => '09:15:00',
                'clock_out' => '18:15:00',
            ]);

            $this->actingAs($admin);

            // 前日リンクが10月18日を指している
            $this->get('/admin/attendance/list')
                ->assertOk()
                ->assertSee(
                    '<a class="previous-day" href="?date=2026-10-18">前日</a>',
                    false
                );

            $response = $this->get(
                '/admin/attendance/list?date=2026-10-18'
            );

            $response->assertOk();
            $response->assertSee(
                '<p class="current-day">2026/10/18</p>',
                false
            );

            // 前日の勤怠だけが表示対象になっている
            $response->assertViewHas(
                'attendanceRecords',
                fn ($records): bool => $records->modelKeys() === [$previousRecord->id]
            );

            $response->assertSeeText('08:45');
            $response->assertSeeText('17:45');
            $response->assertDontSeeText('09:15');
            $response->assertDontSeeText('18:15');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_next_day_attendance_is_displayed(): void
    {
        $this->withoutVite();

        Carbon::setTestNow(
            Carbon::parse('2026-10-19 20:00:00', 'Asia/Tokyo')
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
                'date' => '2026-10-20',
                'clock_in' => '08:45:00',
                'clock_out' => '17:45:00',
            ]);

            $this->actingAs($admin);

            // 翌日リンクが10月20日を指している
            $this->get('/admin/attendance/list')
                ->assertOk()
                ->assertSee(
                    '<a class="next-day" href="?date=2026-10-20">翌日</a>',
                    false
                );

            $response = $this->get(
                '/admin/attendance/list?date=2026-10-20'
            );

            $response->assertOk();
            $response->assertSee(
                '<p class="current-day">2026/10/20</p>',
                false
            );

            // 翌日の勤怠だけが表示対象になっている
            $response->assertViewHas(
                'attendanceRecords',
                fn ($records): bool => $records->modelKeys() === [$nextRecord->id]
            );

            $response->assertSeeText('08:45');
            $response->assertSeeText('17:45');
            $response->assertDontSeeText('09:15');
            $response->assertDontSeeText('18:15');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * 一般ユーザーは管理者の勤怠一覧にアクセスできない
     */
    public function test_normal_user_cannot_access_admin_attendance_list(): void
    {
        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $response = $this->actingAs($user)
            ->get('/admin/attendance/list');

        $response->assertForbidden();
    }

    /**
     * 未ログイン時は管理者ログイン画面に移動する
     */
    public function test_guest_is_redirected_to_admin_login(): void
    {
        $response = $this->get('/admin/attendance/list');

        $response->assertRedirect(route('admin_login'));
    }
}
