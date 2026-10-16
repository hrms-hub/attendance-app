<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttendanceRecordsTableSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::query()->orderBy('id')->get();
        $today = CarbonImmutable::today('Asia/Tokyo');
        $startDate = $today->startOfMonth()->subMonth();
        $endDate = $today->subDay();

        DB::transaction(function () use ($users, $startDate, $endDate): void {
            foreach ($users as $user) {
                for ($date = $startDate; $date->lte($endDate); $date = $date->addDay()) {
                    if ($date->isWeekend()) {
                        continue;
                    }

                    $attendanceRecord = AttendanceRecord::firstOrCreate(
                        [
                            'user_id' => $user->id,
                            'date' => $date->toDateString(),
                        ],
                        [
                            'clock_in' => '09:00:00',
                            'clock_out' => '18:00:00',
                            'comment' => null,
                        ]
                    );

                    if (! $attendanceRecord->wasRecentlyCreated) {
                        continue;
                    }

                    $attendanceRecord->breaks()->create([
                        'break_in' => '12:00:00',
                        'break_out' => '13:00:00',
                    ]);
                }
            }
        });
    }
}
