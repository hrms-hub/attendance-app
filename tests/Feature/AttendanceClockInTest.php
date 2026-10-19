<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceClockInTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_clock_in(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 09:05:00',
            'Asia/Tokyo'
        );

        CarbonImmutable::setTestNow($fixedNow);

        try {
            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            $this->actingAs($user);

            // 勤務外の画面に出勤ボタンがある
            $this->get('/attendance')
                ->assertOk()
                ->assertSee('name="action" value="clock_in"', false);

            // 出勤処理を行う
            $this->post('/attendance', [
                'action' => 'clock_in',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/attendance');

            // 出勤後のステータスを確認する
            $this->get('/attendance')
                ->assertOk()
                ->assertSeeText('出勤中');

            // 固定した日時で出勤記録が保存されている
            $this->assertDatabaseHas('attendance_records', [
                'user_id' => $user->id,
                'date' => '2026-10-19',
                'clock_in' => '09:05:00',
                'clock_out' => null,
            ]);

            $this->assertDatabaseCount('attendance_records', 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_finished_user_cannot_clock_in_again(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 18:30:00',
            'Asia/Tokyo'
        );

        CarbonImmutable::setTestNow($fixedNow);

        try {
            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            $attendanceRecord = $user->attendanceRecords()->create([
                'date' => '2026-10-19',
                'clock_in' => '09:00:00',
                'clock_out' => '18:00:00',
            ]);

            $this->actingAs($user);

            // 退勤済みの画面に出勤ボタンがない
            $this->get('/attendance')
                ->assertOk()
                ->assertSeeText('退勤済')
                ->assertDontSee('name="action" value="clock_in"', false);

            // 直接リクエストしても再出勤できない
            $this->post('/attendance', [
                'action' => 'clock_in',
            ])->assertRedirect('/attendance');

            $this->assertDatabaseCount('attendance_records', 1);

            $this->assertDatabaseHas('attendance_records', [
                'id' => $attendanceRecord->id,
                'user_id' => $user->id,
                'date' => '2026-10-19',
                'clock_in' => '09:00:00',
                'clock_out' => '18:00:00',
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_clock_in_time_is_displayed_in_attendance_list(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 09:05:00',
            'Asia/Tokyo'
        );

        CarbonImmutable::setTestNow($fixedNow);

        try {
            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            $this->actingAs($user);

            // 出勤処理を行う
            $this->post('/attendance', [
                'action' => 'clock_in',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/attendance');

            // 勤怠一覧を開く
            $response = $this->get('/attendance/list');

            $response->assertOk();
            $response->assertViewIs('user.user-attendance-list');

            // 出勤した日付の行に正しい時刻が渡されている
            $response->assertViewHas(
                'formattedAttendanceRecords',
                fn (array $records): bool => collect($records)->contains(
                    fn (array $record): bool => $record['date'] === '10/19(月)'
                        && $record['clock_in'] === '09:05'
                )
            );

            // 実際のHTMLにも日付と出勤時刻が表示されている
            $response->assertSeeText('10/19(月)');
            $response->assertSeeText('09:05');

            $this->assertDatabaseHas('attendance_records', [
                'user_id' => $user->id,
                'date' => '2026-10-19',
                'clock_in' => '09:05:00',
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
