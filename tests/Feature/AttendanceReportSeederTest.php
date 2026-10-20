<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\BreakRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AttendanceRecordsTableSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceReportSeederTest extends TestCase
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

    public function test_seeded_user_report_matches_required_values(): void
    {
        // testing DBで実際のシーダーを実行する
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'user1@example.com')->firstOrFail();

        $records = $user->attendanceRecords()
            ->with('breaks')
            ->get();

        $this->assertCount(92, $records);

        // 全勤怠に12:00〜13:00の休憩が1件ずつある
        $this->assertTrue(
            $records->every(
                fn (AttendanceRecord $record): bool => $record->breaks->count() === 1
                    && $record->breaks->first()->break_in === '12:00:00'
                    && $record->breaks->first()->break_out === '13:00:00'
            )
        );

        // 過去5か月の勤怠はすべて平日である
        $this->assertTrue(
            $records->filter(
                fn (AttendanceRecord $record): bool => $record->date->format('Y-m') !== '2026-10'
            )->every(
                fn (AttendanceRecord $record): bool => $record->date->isWeekday()
            )
        );

        $response = $this->actingAs($user, 'web')
            ->get('/attendance/report');

        $response->assertOk();

        // 総労働744時間、総残業10時間、平均8時間5分
        $response->assertViewHas('summary', [
            'total_work_minutes' => 44640,
            'total_overtime_minutes' => 600,
            'avg_work_minutes' => 485,
        ]);

        $expectedMonthlyTrend = collect([
            '2026-05',
            '2026-06',
            '2026-07',
            '2026-08',
            '2026-09',
        ])->map(fn (string $month): array => [
            'month' => $month,
            'work_minutes' => 7200,
            'overtime_minutes' => 0,
        ])->push([
            'month' => '2026-10',
            'work_minutes' => 8640,
            'overtime_minutes' => 600,
        ])->all();

        $response->assertViewHas('monthlyTrend', $expectedMonthlyTrend);

        $response->assertViewHas('anomalies', [
            'late_count' => 2,
            'early_leave_count' => 1,
            'long_work_count' => 1,
        ]);

        $response->assertSeeText('744h 0m');
        $response->assertSeeText('10h 0m');
        $response->assertSeeText('8h 5m');
    }

    public function test_reseeding_does_not_duplicate_or_change_attendance(): void
    {
        $this->seed(DatabaseSeeder::class);

        $attendanceBefore = AttendanceRecord::query()
            ->orderBy('id')
            ->get()
            ->toArray();

        $breaksBefore = BreakRecord::query()
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            $attendanceBefore,
            AttendanceRecord::query()->orderBy('id')->get()->toArray()
        );

        $this->assertSame(
            $breaksBefore,
            BreakRecord::query()->orderBy('id')->get()->toArray()
        );
    }

    public function test_existing_attendance_and_break_are_preserved(): void
    {
        $user = User::factory()->create([
            'email' => 'user1@example.com',
            'admin_status' => false,
        ]);

        $attendance = $user->attendanceRecords()->create([
            'date' => '2026-10-01',
            'clock_in' => '10:00:00',
            'clock_out' => '17:00:00',
            'comment' => '既存データ保持の確認',
        ]);

        $break = $attendance->breaks()->create([
            'break_in' => '12:15:00',
            'break_out' => '12:45:00',
        ]);

        $attendanceBefore = $attendance->fresh()->getRawOriginal();
        $breakBefore = $break->fresh()->getRawOriginal();

        $this->seed(AttendanceRecordsTableSeeder::class);

        $this->assertSame(
            $attendanceBefore,
            $attendance->fresh()->getRawOriginal()
        );

        $this->assertSame(
            $breakBefore,
            $break->fresh()->getRawOriginal()
        );

        $this->assertSame(1, $attendance->breaks()->count());
    }
}
