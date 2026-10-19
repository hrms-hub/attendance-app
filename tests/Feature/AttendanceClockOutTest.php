<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceClockOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_clock_out(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 18:00:00',
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
                'clock_out' => null,
            ]);

            $this->actingAs($user);

            // 出勤中の画面に退勤ボタンがある
            $this->get('/attendance')
                ->assertOk()
                ->assertSee('name="action" value="clock_out"', false);

            // 退勤処理を行う
            $this->post('/attendance', [
                'action' => 'clock_out',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/attendance');

            $this->get('/attendance')
                ->assertOk()
                ->assertSeeText('退勤済');

            // 同じ勤怠記録に退勤時刻が保存されている
            $this->assertDatabaseHas('attendance_records', [
                'id' => $attendanceRecord->id,
                'user_id' => $user->id,
                'date' => '2026-10-19',
                'clock_in' => '09:00:00',
                'clock_out' => '18:00:00',
            ]);

            $this->assertDatabaseCount('attendance_records', 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_clock_out_time_is_displayed_in_attendance_list(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 09:00:00',
            'Asia/Tokyo'
        );

        CarbonImmutable::setTestNow($fixedNow);

        try {
            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            $this->actingAs($user);

            // 9時に出勤する
            $this->post('/attendance', [
                'action' => 'clock_in',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/attendance');

            // 18時5分に退勤する
            CarbonImmutable::setTestNow($fixedNow->setTime(18, 5));

            $this->post('/attendance', [
                'action' => 'clock_out',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/attendance');

            $response = $this->get('/attendance/list');

            $response->assertOk();
            $response->assertViewIs('user.user-attendance-list');

            // 該当日の行に正しい退勤時刻が渡されている
            $response->assertViewHas(
                'formattedAttendanceRecords',
                fn (array $records): bool => collect($records)->contains(
                    fn (array $record): bool => $record['date'] === '10/19(月)'
                        && $record['clock_out'] === '18:05'
                )
            );

            $response->assertSeeText('10/19(月)');
            $response->assertSeeText('18:05');

            $this->assertDatabaseHas('attendance_records', [
                'user_id' => $user->id,
                'date' => '2026-10-19',
                'clock_in' => '09:00:00',
                'clock_out' => '18:05:00',
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
