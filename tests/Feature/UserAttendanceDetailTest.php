<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAttendanceDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_matches_logged_in_user(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'name' => '勤怠テストユーザー',
            'admin_status' => false,
        ]);

        $attendanceRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-19',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        $response = $this->actingAs($user)
            ->get('/attendance/detail/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertViewIs('user.user-detail');

        // ログインユーザーが画面に渡されている
        $response->assertViewHas(
            'user',
            fn (User $viewUser): bool => $viewUser->id === $user->id
        );

        // 名前欄に本人の氏名が表示されている
        $response->assertSee(
            'name="name" value="勤怠テストユーザー"',
            false
        );
    }

    public function test_date_matches_selected_attendance(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $targetRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        $user->attendanceRecords()->create([
            'date' => '2026-10-19',
            'clock_in' => '10:00:00',
            'clock_out' => '19:00:00',
        ]);

        // 10月15日の勤怠詳細を開く
        $response = $this->actingAs($user)
            ->get('/attendance/detail/'.$targetRecord->id);

        $response->assertOk();

        $response->assertViewHas(
            'data',
            fn (array $data): bool => $data['id'] === $targetRecord->id
                && $data['year'] === '2026年'
                && $data['date'] === '10月15日'
        );

        // 年と月日の入力欄を確認する
        $response->assertSee(
            'class="form__input form__input--date" type="text" value="2026年" readonly',
            false
        );
        $response->assertSee(
            'name="new_date" value="10月15日"',
            false
        );
    }

    public function test_clock_times_match_selected_attendance(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $targetRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:05:00',
            'clock_out' => '18:15:00',
        ]);

        // 別の日には異なる時刻を登録する
        $user->attendanceRecords()->create([
            'date' => '2026-10-19',
            'clock_in' => '10:00:00',
            'clock_out' => '19:00:00',
        ]);

        $response = $this->actingAs($user)
            ->get('/attendance/detail/'.$targetRecord->id);

        $response->assertOk();

        $response->assertViewHas(
            'data',
            fn (array $data): bool => $data['id'] === $targetRecord->id
                && $data['clock_in'] === '09:05'
                && $data['clock_out'] === '18:15'
        );

        // 出勤・退勤それぞれの入力欄を確認する
        $response->assertSee(
            'name="new_clock_in" value="09:05"',
            false
        );
        $response->assertSee(
            'name="new_clock_out" value="18:15"',
            false
        );
    }

    public function test_break_times_match_selected_attendance(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $targetRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        $targetRecord->breaks()->create([
            'break_in' => '12:05:00',
            'break_out' => '12:50:00',
        ]);

        $targetRecord->breaks()->create([
            'break_in' => '15:00:00',
            'break_out' => '15:15:00',
        ]);

        $response = $this->actingAs($user)
            ->get('/attendance/detail/'.$targetRecord->id);

        $response->assertOk();

        // 選択した勤怠の休憩が2件とも渡されている
        $response->assertViewHas(
            'data',
            fn (array $data): bool => $data['id'] === $targetRecord->id
                && $data['breaks'] === [
                    ['break_in' => '12:05', 'break_out' => '12:50'],
                    ['break_in' => '15:00', 'break_out' => '15:15'],
                ]
        );

        // 各休憩の開始・終了の入力欄を確認する
        $response->assertSee(
            'name="new_break_in[0]" value="12:05"',
            false
        );
        $response->assertSee(
            'name="new_break_out[0]" value="12:50"',
            false
        );
        $response->assertSee(
            'name="new_break_in[1]" value="15:00"',
            false
        );
        $response->assertSee(
            'name="new_break_out[1]" value="15:15"',
            false
        );
    }
}
