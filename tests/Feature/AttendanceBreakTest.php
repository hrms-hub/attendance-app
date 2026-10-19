<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceBreakTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_start_break(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 12:00:00',
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

            // 出勤中の画面に休憩入ボタンがある
            $this->get('/attendance')
                ->assertOk()
                ->assertSee('name="action" value="break_in"', false);

            // 休憩開始処理を行う
            $this->post('/attendance', [
                'action' => 'break_in',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/attendance');

            $this->get('/attendance')
                ->assertOk()
                ->assertSeeText('休憩中');

            // 勤怠に紐づく休憩が1件保存されている
            $this->assertSame(1, $attendanceRecord->breaks()->count());

            $breakRecord = $attendanceRecord->breaks()->firstOrFail();

            $this->assertSame('12:00:00', $breakRecord->break_in);
            $this->assertNull($breakRecord->break_out);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_user_can_start_break_multiple_times_per_day(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 12:00:00',
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

            // 1回目の休憩を開始する
            $this->post('/attendance', [
                'action' => 'break_in',
            ])->assertRedirect('/attendance');

            // 13時に休憩を終了する
            CarbonImmutable::setTestNow($fixedNow->setTime(13, 0));

            $this->post('/attendance', [
                'action' => 'break_out',
            ])->assertRedirect('/attendance');

            // 休憩入ボタンが再表示される
            $this->get('/attendance')
                ->assertOk()
                ->assertSeeText('出勤中')
                ->assertSee('name="action" value="break_in"', false);

            // 15時に2回目の休憩を開始する
            CarbonImmutable::setTestNow($fixedNow->setTime(15, 0));

            $this->post('/attendance', [
                'action' => 'break_in',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/attendance');

            // 1回目を残したまま2回目が保存されている
            $breaks = $attendanceRecord->breaks()->orderBy('id')->get();

            $this->assertCount(2, $breaks);
            $this->assertSame('12:00:00', $breaks[0]->break_in);
            $this->assertSame('13:00:00', $breaks[0]->break_out);
            $this->assertSame('15:00:00', $breaks[1]->break_in);
            $this->assertNull($breaks[1]->break_out);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_user_can_end_break(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 12:00:00',
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

            // 12時に休憩を開始する
            $this->post('/attendance', [
                'action' => 'break_in',
            ])->assertRedirect('/attendance');

            // 休憩中の画面に休憩戻ボタンがある
            $this->get('/attendance')
                ->assertOk()
                ->assertSeeText('休憩中')
                ->assertSee('name="action" value="break_out"', false);

            // 13時に休憩を終了する
            CarbonImmutable::setTestNow($fixedNow->setTime(13, 0));

            $this->post('/attendance', [
                'action' => 'break_out',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/attendance');

            $this->get('/attendance')
                ->assertOk()
                ->assertSeeText('出勤中');

            // 同じ休憩記録に終了時刻が保存されている
            $this->assertSame(1, $attendanceRecord->breaks()->count());

            $breakRecord = $attendanceRecord->breaks()->firstOrFail();

            $this->assertSame('12:00:00', $breakRecord->break_in);
            $this->assertSame('13:00:00', $breakRecord->break_out);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_user_can_end_break_multiple_times_per_day(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 12:00:00',
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

            // 1回目：12時から13時まで休憩する
            $this->post('/attendance', [
                'action' => 'break_in',
            ])->assertRedirect('/attendance');

            CarbonImmutable::setTestNow($fixedNow->setTime(13, 0));

            $this->post('/attendance', [
                'action' => 'break_out',
            ])->assertRedirect('/attendance');

            // 2回目：15時に休憩を開始する
            CarbonImmutable::setTestNow($fixedNow->setTime(15, 0));

            $this->post('/attendance', [
                'action' => 'break_in',
            ])->assertRedirect('/attendance');

            // 2回目も休憩戻ボタンが表示される
            $this->get('/attendance')
                ->assertOk()
                ->assertSeeText('休憩中')
                ->assertSee('name="action" value="break_out"', false);

            // 15時15分に2回目の休憩を終了する
            CarbonImmutable::setTestNow($fixedNow->setTime(15, 15));

            $this->post('/attendance', [
                'action' => 'break_out',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/attendance');

            $this->get('/attendance')
                ->assertOk()
                ->assertSeeText('出勤中');

            // 両方の休憩の開始・終了時刻が保存されている
            $breaks = $attendanceRecord->breaks()->orderBy('id')->get();

            $this->assertCount(2, $breaks);
            $this->assertSame('12:00:00', $breaks[0]->break_in);
            $this->assertSame('13:00:00', $breaks[0]->break_out);
            $this->assertSame('15:00:00', $breaks[1]->break_in);
            $this->assertSame('15:15:00', $breaks[1]->break_out);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_break_time_is_displayed_in_attendance_list(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 12:00:00',
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

            // 12時に休憩を開始する
            $this->post('/attendance', [
                'action' => 'break_in',
            ])->assertRedirect('/attendance');

            // 13時に休憩を終了する
            CarbonImmutable::setTestNow($fixedNow->setTime(13, 0));

            $this->post('/attendance', [
                'action' => 'break_out',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect('/attendance');

            // 開始・終了時刻が保存されている
            $breakRecord = $attendanceRecord->breaks()->firstOrFail();

            $this->assertSame('12:00:00', $breakRecord->break_in);
            $this->assertSame('13:00:00', $breakRecord->break_out);

            $response = $this->get('/attendance/list');

            $response->assertOk();
            $response->assertViewIs('user.user-attendance-list');

            // 該当日の休憩合計が1時間になっている
            $response->assertViewHas(
                'formattedAttendanceRecords',
                fn (array $records): bool => collect($records)->contains(
                    fn (array $record): bool => $record['date'] === '10/19(月)'
                        && $record['total_break_time'] === '01:00:00'
                )
            );

            $response->assertSeeText('10/19(月)');
            $response->assertSeeText('1:00');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
