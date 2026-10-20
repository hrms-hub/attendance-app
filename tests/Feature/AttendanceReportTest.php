<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        CarbonImmutable::setTestNow(
            CarbonImmutable::create(2026, 10, 20, 18, 30, 0, 'Asia/Tokyo')
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_guest_cannot_access_report(): void
    {
        $this->get('/attendance/report')
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_statistics_are_calculated_correctly(): void
    {
        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $otherUser = User::factory()->create([
            'admin_status' => false,
        ]);

        // 集計対象：7日、実働59時間、残業6時間
        collect([
            ['2026-05-01', '09:00:00', '18:00:00'],
            ['2026-09-30', '09:30:00', '17:00:00'],
            ['2026-10-05', '09:00:00', '18:00:00'],
            ['2026-10-06', '09:00:00', '20:00:00'],
            ['2026-10-07', '09:30:00', '18:00:00'],
            ['2026-10-08', '09:00:00', '17:00:00'],
            ['2026-10-09', '08:00:00', '21:00:00'],
        ])->each(function (array $row) use ($user): void {
            $this->createAttendance(
                $user,
                $row[0],
                $row[1],
                $row[2]
            );
        });

        // 期間外・別ユーザーのデータは集計に含めない
        $this->createAttendance($user, '2026-04-30');
        $this->createAttendance($user, '2026-11-01');

        $this->createAttendance(
            $otherUser,
            '2026-10-05',
            '08:00:00',
            '23:00:00'
        );

        $response = $this->actingAs($user, 'web')
            ->get('/attendance/report');

        $response->assertOk();
        $response->assertViewIs('reports.index');

        // 59時間＝3540分、平均は3540÷7を分単位で切り捨てる
        $response->assertViewHas('summary', [
            'total_work_minutes' => 3540,
            'total_overtime_minutes' => 360,
            'avg_work_minutes' => 505,
        ]);

        // データがない月も含め、古い月から6か月分を表示する
        $response->assertViewHas('monthlyTrend', [
            [
                'month' => '2026-05',
                'work_minutes' => 480,
                'overtime_minutes' => 0,
            ],
            [
                'month' => '2026-06',
                'work_minutes' => 0,
                'overtime_minutes' => 0,
            ],
            [
                'month' => '2026-07',
                'work_minutes' => 0,
                'overtime_minutes' => 0,
            ],
            [
                'month' => '2026-08',
                'work_minutes' => 0,
                'overtime_minutes' => 0,
            ],
            [
                'month' => '2026-09',
                'work_minutes' => 390,
                'overtime_minutes' => 0,
            ],
            [
                'month' => '2026-10',
                'work_minutes' => 2670,
                'overtime_minutes' => 360,
            ],
        ]);

        // 過去月の遅刻・早退は当月の回数に含めない
        // 実働10時間ちょうどの日は長時間労働に含めない
        $response->assertViewHas('anomalies', [
            'late_count' => 1,
            'early_leave_count' => 1,
            'long_work_count' => 1,
        ]);

        $response->assertSeeText('マイ勤怠レポート');
        $response->assertSeeText('59h 0m');
        $response->assertSeeText('6h 0m');
        $response->assertSeeText('8h 25m');
    }

    public function test_user_without_attendance_receives_zero_statistics(): void
    {
        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $response = $this->actingAs($user, 'web')
            ->get('/attendance/report');

        $response->assertOk();

        $response->assertViewHas('summary', [
            'total_work_minutes' => 0,
            'total_overtime_minutes' => 0,
            'avg_work_minutes' => 0,
        ]);

        $expectedMonthlyTrend = collect([
            '2026-05',
            '2026-06',
            '2026-07',
            '2026-08',
            '2026-09',
            '2026-10',
        ])->map(fn (string $month): array => [
            'month' => $month,
            'work_minutes' => 0,
            'overtime_minutes' => 0,
        ])->all();

        $response->assertViewHas('monthlyTrend', $expectedMonthlyTrend);

        $response->assertViewHas('anomalies', [
            'late_count' => 0,
            'early_leave_count' => 0,
            'long_work_count' => 0,
        ]);

        $response->assertSeeText('0h 0m');
    }

    public function test_unverified_user_is_redirected_to_verification_notice(): void
    {
        $user = User::factory()->unverified()->create([
            'admin_status' => false,
        ]);

        $this->actingAs($user, 'web')
            ->get('/attendance/report')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_admin_cannot_access_general_user_report(): void
    {
        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        $this->actingAs($admin, 'web')
            ->get('/attendance/report')
            ->assertForbidden();
    }

    public function test_unfinished_attendance_is_excluded_from_totals_and_average(): void
    {
        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $this->createAttendance($user, '2026-10-19');

        $this->createAttendance(
            $user,
            '2026-10-20',
            '09:30:00',
            null
        );

        $response = $this->actingAs($user, 'web')
            ->get('/attendance/report');

        $response->assertOk();

        // 退勤済みの1日だけで労働時間・平均を計算する
        $response->assertViewHas('summary', [
            'total_work_minutes' => 480,
            'total_overtime_minutes' => 0,
            'avg_work_minutes' => 480,
        ]);

        // 未退勤でも、出勤時刻による遅刻判定は行う
        $response->assertViewHas('anomalies', [
            'late_count' => 1,
            'early_leave_count' => 0,
            'long_work_count' => 0,
        ]);
    }

    public function test_seconds_are_added_before_conversion_to_minutes(): void
    {
        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        // 各日が実働8時間40秒、残業40秒
        $this->createAttendance(
            $user,
            '2026-10-01',
            '09:00:00',
            '18:00:40'
        );

        $this->createAttendance(
            $user,
            '2026-10-02',
            '09:00:00',
            '18:00:40'
        );

        $response = $this->actingAs($user, 'web')
            ->get('/attendance/report');

        $response->assertOk();

        // 合計16時間1分20秒、残業1分20秒、平均8時間40秒
        $response->assertViewHas('summary', [
            'total_work_minutes' => 961,
            'total_overtime_minutes' => 1,
            'avg_work_minutes' => 480,
        ]);

        $this->assertSame([
            'month' => '2026-10',
            'work_minutes' => 961,
            'overtime_minutes' => 1,
        ], $response['monthlyTrend'][5]);
    }

    public function test_anomalies_use_strict_time_thresholds(): void
    {
        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        // 09:00・18:00ちょうどは遅刻・早退に含めない
        $this->createAttendance($user, '2026-10-01');

        // 実働10時間ちょうどは長時間労働に含めない
        $this->createAttendance(
            $user,
            '2026-10-02',
            '09:00:00',
            '20:00:00'
        );

        // 実働10時間を1秒超えるため、長時間労働に含める
        $this->createAttendance(
            $user,
            '2026-10-03',
            '09:00:00',
            '20:00:01'
        );

        // 1秒の遅刻・早退も判定する
        $this->createAttendance(
            $user,
            '2026-10-04',
            '09:00:01',
            '17:59:59'
        );

        $response = $this->actingAs($user, 'web')
            ->get('/attendance/report');

        $response->assertOk();

        $response->assertViewHas('anomalies', [
            'late_count' => 1,
            'early_leave_count' => 1,
            'long_work_count' => 1,
        ]);
    }

    /**
     * 1時間の休憩を含む勤怠を作成する。
     */
    private function createAttendance(
        User $user,
        string $date,
        string $clockIn = '09:00:00',
        ?string $clockOut = '18:00:00'
    ): AttendanceRecord {
        $attendance = $user->attendanceRecords()->create([
            'date' => $date,
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
        ]);

        $attendance->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        return $attendance;
    }
}
