<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_off_duty_status_is_displayed(): void
    {
        $this->withoutVite();

        // 勤怠データがない一般ユーザーを用意する
        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $response = $this->actingAs($user)->get('/attendance');

        $response->assertOk();
        $response->assertSeeText('勤務外');
    }

    public function test_working_status_is_displayed(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 10:00:00',
            'Asia/Tokyo'
        );

        CarbonImmutable::setTestNow($fixedNow);

        try {
            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            // 当日の出勤記録を作成する
            $user->attendanceRecords()->create([
                'date' => '2026-10-19',
                'clock_in' => '09:00:00',
                'clock_out' => null,
            ]);

            $response = $this->actingAs($user)->get('/attendance');

            $response->assertOk();
            $response->assertSeeText('出勤中');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_on_break_status_is_displayed(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 12:30:00',
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

            // 終了していない休憩を作成する
            $attendanceRecord->breaks()->create([
                'break_in' => '12:00:00',
                'break_out' => null,
            ]);

            $response = $this->actingAs($user)->get('/attendance');

            $response->assertOk();
            $response->assertSeeText('休憩中');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_finished_status_is_displayed(): void
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

            // 当日の出勤・退勤記録を作成する
            $user->attendanceRecords()->create([
                'date' => '2026-10-19',
                'clock_in' => '09:00:00',
                'clock_out' => '18:00:00',
            ]);

            $response = $this->actingAs($user)->get('/attendance');

            $response->assertOk();
            $response->assertSeeText('退勤済');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
