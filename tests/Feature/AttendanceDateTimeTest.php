<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceDateTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_date_and_time_are_displayed(): void
    {
        $this->withoutVite();

        $fixedNow = CarbonImmutable::parse(
            '2026-10-19 09:05:00',
            'Asia/Tokyo'
        );

        CarbonImmutable::setTestNow($fixedNow);

        try {
            $user = User::factory()->create([
                'admin_status' => false,
            ]);

            $response = $this->actingAs($user)->get('/attendance');

            $response->assertOk();
            $response->assertViewIs('user.attendance-register');

            // 画面へ渡される日付・曜日・時刻を確認する
            $response->assertViewHas(
                'formattedDate',
                '2026年10月19日(月)'
            );
            $response->assertViewHas('formattedTime', '09:05');

            // HTMLの入力欄に正しい値が設定されている
            $response->assertSee(
                'value="2026年10月19日(月)"',
                false
            );
            $response->assertSee(
                'id="currentTime" value="09:05"',
                false
            );
        } finally {
            // 他のテストに影響しないよう日時の固定を解除する
            CarbonImmutable::setTestNow();
        }
    }
}
