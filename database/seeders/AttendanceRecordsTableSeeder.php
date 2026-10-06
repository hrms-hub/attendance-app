<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttendanceRecordsTableSeeder extends Seeder
{
    private const USER_EMAIL = 'user@example.com';
    private const SAMPLE_DAYS_AGO = [3, 2, 1];

    public function run(): void
    {
        $user = User::where('email', self::USER_EMAIL)->firstOrFail();
        $today = CarbonImmutable::today('Asia/Tokyo');

        DB::transaction(function () use ($user, $today): void {
            foreach (self::SAMPLE_DAYS_AGO as $days_ago) {
                $attendance_date = $today
                    ->subDays($days_ago)
                    ->toDateString();

                $attendance_record = AttendanceRecord::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'date' => $attendance_date,
                    ],
                    [
                        'clock_in' => '09:00:00',
                        'clock_out' => '18:00:00',
                        'comment' => null,
                    ]
                );

                if (!$attendance_record->wasRecentlyCreated) {
                    continue;
                }

                $attendance_record->breaks()->create([
                    'break_in' => '12:00:00',
                    'break_out' => '13:00:00',
                ]);
            }
        });
    }
}