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
        $start_date = $today->startOfMonth()->subMonth();
        $end_date = $today->subDay();

        DB::transaction(function () use ($users, $start_date, $end_date): void {
            foreach ($users as $user) {
                for ($date = $start_date; $date->lte($end_date); $date = $date->addDay()) {
                    if ($date->isWeekend()) {
                        continue;
                    }

                    $attendance_record = AttendanceRecord::firstOrCreate(
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

                    if (! $attendance_record->wasRecentlyCreated) {
                        continue;
                    }

                    $attendance_record->breaks()->create([
                        'break_in' => '12:00:00',
                        'break_out' => '13:00:00',
                    ]);
                }
            }
        });
    }
}
