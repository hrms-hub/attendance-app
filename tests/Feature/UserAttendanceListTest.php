<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAttendanceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_own_attendance_records_are_displayed(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 20:00:00',
            'Asia/Tokyo'
        );

        CarbonImmutable::setTestNow($fixedNow);

        try {
            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            $otherUser = User::factory()->create([
                'admin_status' => false,
            ]);

            $firstRecord = $user->attendanceRecords()->create([
                'date' => '2026-10-01',
                'clock_in' => '09:05:00',
                'clock_out' => '18:05:00',
            ]);

            $secondRecord = $user->attendanceRecords()->create([
                'date' => '2026-10-19',
                'clock_in' => '10:15:00',
                'clock_out' => '19:15:00',
            ]);

            $otherUser->attendanceRecords()->create([
                'date' => '2026-10-19',
                'clock_in' => '07:45:00',
                'clock_out' => '16:45:00',
            ]);

            $response = $this->actingAs($user)
                ->get('/attendance/list');

            $response->assertOk();
            $response->assertViewIs('user.user-attendance-list');

            // 勤怠データがある行は、本人の2件だけになっている
            $response->assertViewHas(
                'formattedAttendanceRecords',
                fn (array $records): bool => collect($records)
                    ->whereNotNull('id')
                    ->pluck('id')
                    ->values()
                    ->all() === [$firstRecord->id, $secondRecord->id]
            );

            // 本人の両方の勤怠が画面に表示されている
            $response->assertSeeText('09:05');
            $response->assertSeeText('18:05');
            $response->assertSeeText('10:15');
            $response->assertSeeText('19:15');

            // 別ユーザーの勤怠は表示されていない
            $response->assertDontSeeText('07:45');
            $response->assertDontSeeText('16:45');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_current_month_is_displayed_by_default(): void
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

            // 月を指定せずに一覧を開く
            $response = $this->actingAs($user)
                ->get('/attendance/list');

            $response->assertOk();

            $response->assertViewHas(
                'date',
                fn (CarbonImmutable $date): bool => $date->format('Y-m') === '2026-10'
            );

            // 月表示の欄に現在の月が表示されている
            $response->assertSee(
                '<p class="current-month">2026/10</p>',
                false
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_previous_month_attendance_is_displayed(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 20:00:00',
            'Asia/Tokyo'
        );

        CarbonImmutable::setTestNow($fixedNow);

        try {
            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            $previousRecord = $user->attendanceRecords()->create([
                'date' => '2026-09-10',
                'clock_in' => '08:45:00',
                'clock_out' => '17:45:00',
            ]);

            $user->attendanceRecords()->create([
                'date' => '2026-10-19',
                'clock_in' => '09:15:00',
                'clock_out' => '18:15:00',
            ]);

            $this->actingAs($user);

            // 現在月の前月リンクが9月を指している
            $this->get('/attendance/list')
                ->assertOk()
                ->assertSee(
                    '<a class="previous-month" href="?date=2026-09">前月</a>',
                    false
                );

            // 前月リンクのURLを開く
            $response = $this->get('/attendance/list?date=2026-09');

            $response->assertOk();
            $response->assertSee(
                '<p class="current-month">2026/09</p>',
                false
            );

            // 前月の勤怠だけが表示対象になっている
            $response->assertViewHas(
                'formattedAttendanceRecords',
                fn (array $records): bool => collect($records)
                    ->whereNotNull('id')
                    ->pluck('id')
                    ->values()
                    ->all() === [$previousRecord->id]
            );

            $response->assertSeeText('08:45');
            $response->assertSeeText('17:45');
            $response->assertDontSeeText('09:15');
            $response->assertDontSeeText('18:15');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_next_month_attendance_is_displayed(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 20:00:00',
            'Asia/Tokyo'
        );

        CarbonImmutable::setTestNow($fixedNow);

        try {
            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            $user->attendanceRecords()->create([
                'date' => '2026-10-19',
                'clock_in' => '09:15:00',
                'clock_out' => '18:15:00',
            ]);

            $nextRecord = $user->attendanceRecords()->create([
                'date' => '2026-11-10',
                'clock_in' => '08:45:00',
                'clock_out' => '17:45:00',
            ]);

            $this->actingAs($user);

            // 現在月の翌月リンクが11月を指している
            $this->get('/attendance/list')
                ->assertOk()
                ->assertSee(
                    '<a class="next-month" href="?date=2026-11">翌月</a>',
                    false
                );

            // 翌月リンクのURLを開く
            $response = $this->get('/attendance/list?date=2026-11');

            $response->assertOk();
            $response->assertSee(
                '<p class="current-month">2026/11</p>',
                false
            );

            // 翌月の勤怠だけが表示対象になっている
            $response->assertViewHas(
                'formattedAttendanceRecords',
                fn (array $records): bool => collect($records)
                    ->whereNotNull('id')
                    ->pluck('id')
                    ->values()
                    ->all() === [$nextRecord->id]
            );

            $response->assertSeeText('08:45');
            $response->assertSeeText('17:45');
            $response->assertDontSeeText('09:15');
            $response->assertDontSeeText('18:15');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_detail_link_opens_selected_attendance(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 20:00:00',
            'Asia/Tokyo'
        );

        CarbonImmutable::setTestNow($fixedNow);

        try {
            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            $user->attendanceRecords()->create([
                'date' => '2026-10-01',
                'clock_in' => '09:05:00',
                'clock_out' => '18:05:00',
            ]);

            $targetRecord = $user->attendanceRecords()->create([
                'date' => '2026-10-19',
                'clock_in' => '10:15:00',
                'clock_out' => '19:15:00',
            ]);

            $this->actingAs($user);

            $detailPath = '/attendance/'.$targetRecord->id;

            // 一覧に選択した勤怠の詳細リンクがある
            $this->get('/attendance/list')
                ->assertOk()
                ->assertSee(
                    'href="'.url($detailPath).'"',
                    false
                );

            // 詳細リンクのURLを開く
            $response = $this->get($detailPath);

            $response->assertOk();
            $response->assertViewIs('user.user-detail');
            $response->assertSeeText('勤怠詳細');

            // 選択した勤怠のデータが渡されている
            $response->assertViewHas(
                'data',
                fn (array $data): bool => $data['id'] === $targetRecord->id
                    && $data['year'] === '2026年'
                    && $data['date'] === '10月19日'
                    && $data['clock_in'] === '10:15'
                    && $data['clock_out'] === '19:15'
            );

            // 入力欄にも選択した日の情報が表示されている
            $response->assertSee(
                'name="new_date" value="10月19日"',
                false
            );
            $response->assertSee(
                'name="new_clock_in" value="10:15"',
                false
            );
            $response->assertSee(
                'name="new_clock_out" value="19:15"',
                false
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
