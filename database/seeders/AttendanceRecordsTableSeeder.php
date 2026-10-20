<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttendanceRecordsTableSeeder extends Seeder
{
    /**
     * ユーザーごとの勤怠ダミーデータを作成する。
     */
    public function run(): void
    {
        $users = User::query()->orderBy('id')->get();
        $today = CarbonImmutable::today('Asia/Tokyo');

        DB::transaction(function () use ($users, $today): void {
            $users->each(function (User $user) use ($today): void {
                if ($user->email === 'user1@example.com') {
                    $this->seedReportAttendance($user, $today);

                    return;
                }

                $this->seedStandardAttendance($user, $today);
            });
        });
    }

    /**
     * レポート確認用の92日分の勤怠を作成する。
     *
     * @param  User  $user  対象ユーザー
     * @param  CarbonImmutable  $today  基準日
     */
    private function seedReportAttendance(
        User $user,
        CarbonImmutable $today
    ): void {
        // 過去5か月：各月の最初の平日15日を通常勤務にする
        collect(range(1, 5))->each(function (int $monthsAgo) use ($user, $today): void {
            $month = $today->startOfMonth()->subMonths($monthsAgo);

            collect(range(1, $month->daysInMonth))
                ->map(fn (int $day): CarbonImmutable => $month->day($day))
                ->filter(fn (CarbonImmutable $date): bool => $date->isWeekday())
                ->take(15)
                ->each(function (CarbonImmutable $date) use ($user): void {
                    $this->createAttendance(
                        $user,
                        $date,
                        '09:00:00',
                        '18:00:00'
                    );
                });
        });

        // 当月：通常10、残業3、遅刻2、早退1、長時間労働1
        $patterns = collect([
            ['days' => 10, 'clock_in' => '09:00:00', 'clock_out' => '18:00:00'],
            ['days' => 3, 'clock_in' => '09:00:00', 'clock_out' => '20:00:00'],
            ['days' => 2, 'clock_in' => '09:30:00', 'clock_out' => '18:00:00'],
            ['days' => 1, 'clock_in' => '09:00:00', 'clock_out' => '17:00:00'],
            ['days' => 1, 'clock_in' => '08:00:00', 'clock_out' => '21:00:00'],
        ])->flatMap(fn (array $pattern): array => array_fill(
            0,
            $pattern['days'],
            [
                'clock_in' => $pattern['clock_in'],
                'clock_out' => $pattern['clock_out'],
            ]
        ))->values();

        $currentMonth = $today->startOfMonth();

        $patterns->each(function (array $pattern, int $index) use ($user, $currentMonth): void {
            $this->createAttendance(
                $user,
                $currentMonth->addDays($index),
                $pattern['clock_in'],
                $pattern['clock_out']
            );
        });
    }

    /**
     * 前月1日から前日までの平日に通常勤務を作成する。
     *
     * @param  User  $user  対象ユーザー
     * @param  CarbonImmutable  $today  基準日
     */
    private function seedStandardAttendance(
        User $user,
        CarbonImmutable $today
    ): void {
        $startDate = $today->startOfMonth()->subMonth();
        $endDate = $today->subDay();
        $days = (int) $startDate->diffInDays($endDate);

        collect(range(0, $days))
            ->map(fn (int $offset): CarbonImmutable => $startDate->addDays($offset))
            ->filter(fn (CarbonImmutable $date): bool => $date->isWeekday())
            ->each(function (CarbonImmutable $date) use ($user): void {
                $this->createAttendance(
                    $user,
                    $date,
                    '09:00:00',
                    '18:00:00'
                );
            });
    }

    /**
     * 勤怠を新規作成した場合だけ、固定休憩を追加する。
     *
     * @param  User  $user  対象ユーザー
     * @param  CarbonImmutable  $date  勤怠日
     * @param  string  $clockIn  出勤時刻
     * @param  string  $clockOut  退勤時刻
     */
    private function createAttendance(
        User $user,
        CarbonImmutable $date,
        string $clockIn,
        string $clockOut
    ): void {
        $attendanceRecord = AttendanceRecord::firstOrCreate(
            [
                'user_id' => $user->id,
                'date' => $date->toDateString(),
            ],
            [
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                'comment' => null,
            ]
        );

        if (! $attendanceRecord->wasRecentlyCreated) {
            return;
        }

        $attendanceRecord->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);
    }
}
