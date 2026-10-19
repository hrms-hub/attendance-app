<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAttendanceDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_attendance_details_are_displayed(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create([
            'name' => '管理者',
            'admin_status' => true,
        ]);

        $user = User::factory()->create([
            'name' => '対象ユーザー',
            'admin_status' => false,
        ]);

        $targetRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:05:00',
            'clock_out' => '18:15:00',
            'comment' => '確認対象の備考',
        ]);

        $targetRecord->breaks()->create([
            'break_in' => '12:05:00',
            'break_out' => '12:50:00',
        ]);

        $targetRecord->breaks()->create([
            'break_in' => '15:00:00',
            'break_out' => '15:15:00',
        ]);

        // 別の日には異なる内容を用意する
        $user->attendanceRecords()->create([
            'date' => '2026-10-19',
            'clock_in' => '10:00:00',
            'clock_out' => '19:00:00',
            'comment' => '別の日の備考',
        ]);

        $response = $this->actingAs($admin)
            ->get('/admin/attendance/'.$targetRecord->id);

        $response->assertOk();
        $response->assertViewIs('admin.admin-detail');

        $response->assertViewHas(
            'user',
            fn (User $viewUser): bool => $viewUser->id === $user->id
        );

        $response->assertViewHas(
            'attendanceRecord',
            fn (array $data): bool => $data['id'] === $targetRecord->id
        );

        $document = new \DOMDocument;
        $document->loadHTML(
            '<?xml encoding="UTF-8"?>'.$response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new \DOMXPath($document);

        // 入力欄の名前と期待する表示値
        $expectedInputs = [
            'name' => '対象ユーザー',
            'new_date' => '10月15日',
            'new_clock_in' => '09:05',
            'new_clock_out' => '18:15',
            'new_break_in[0]' => '12:05',
            'new_break_out[0]' => '12:50',
            'new_break_in[1]' => '15:00',
            'new_break_out[1]' => '15:15',
        ];

        foreach ($expectedInputs as $name => $expectedValue) {
            $inputs = $xpath->query('//input[@name="'.$name.'"]');

            $this->assertSame(1, $inputs->length, $name);
            $this->assertSame(
                $expectedValue,
                $inputs->item(0)->getAttribute('value'),
                $name
            );
        }

        $response->assertSee('value="2026年"', false);

        $comments = $xpath->query('//textarea[@name="comment"]');

        $this->assertSame(1, $comments->length);
        $this->assertSame(
            '確認対象の備考',
            $comments->item(0)->textContent
        );
    }

    public function test_clock_in_after_clock_out_is_rejected(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $attendanceRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $detailPath = '/admin/attendance/'.$attendanceRecord->id;
        $postPath = '/attendance/'.$attendanceRecord->id;

        $this->actingAs($admin);

        // 出勤19時・退勤18時で修正する
        $response = $this->from($detailPath)->post($postPath, [
            'new_clock_in' => '19:00',
            'new_clock_out' => '18:00',
            'comment' => '管理者による修正',
        ]);

        $response->assertRedirect($detailPath);
        $response->assertSessionHasErrors([
            'new_clock_out' => '出勤時間もしくは退勤時間が不適切な値です',
        ]);

        $this->get($detailPath)
            ->assertOk()
            ->assertSeeText('出勤時間もしくは退勤時間が不適切な値です');

        // 元の勤怠が変更されていない
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $this->assertSame(0, $attendanceRecord->applications()->count());
    }

    public function test_break_start_after_clock_out_is_rejected(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $attendanceRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $originalBreak = $attendanceRecord->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $detailPath = '/admin/attendance/'.$attendanceRecord->id;
        $postPath = '/attendance/'.$attendanceRecord->id;

        $this->actingAs($admin);

        $response = $this->from($detailPath)->post($postPath, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['19:00'],
            'new_break_out' => ['19:30'],
            'comment' => '管理者による修正',
        ]);

        $response->assertRedirect($detailPath);
        $response->assertSessionHasErrors([
            'new_break_in.0' => '休憩時間が不適切な値です',
        ]);

        $this->get($detailPath)
            ->assertOk()
            ->assertSeeText('休憩時間が不適切な値です');

        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        // 元の休憩記録も維持されている
        $this->assertSame(1, $attendanceRecord->breaks()->count());

        $originalBreak->refresh();

        $this->assertSame('12:00:00', $originalBreak->break_in);
        $this->assertSame('13:00:00', $originalBreak->break_out);

        $this->assertSame(0, $attendanceRecord->applications()->count());
    }

    public function test_break_end_after_clock_out_is_rejected(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $attendanceRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $originalBreak = $attendanceRecord->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $detailPath = '/admin/attendance/'.$attendanceRecord->id;
        $postPath = '/attendance/'.$attendanceRecord->id;

        $this->actingAs($admin);

        $response = $this->from($detailPath)->post($postPath, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['17:00'],
            'new_break_out' => ['18:30'],
            'comment' => '管理者による修正',
        ]);

        $response->assertRedirect($detailPath);
        $response->assertSessionHasErrors([
            'new_break_out.0' => '休憩時間もしくは退勤時間が不適切な値です',
        ]);

        $this->get($detailPath)
            ->assertOk()
            ->assertSeeText('休憩時間もしくは退勤時間が不適切な値です');

        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        // 元の休憩記録も維持されている
        $this->assertSame(1, $attendanceRecord->breaks()->count());

        $originalBreak->refresh();

        $this->assertSame('12:00:00', $originalBreak->break_in);
        $this->assertSame('13:00:00', $originalBreak->break_out);

        $this->assertSame(0, $attendanceRecord->applications()->count());
    }

    public function test_comment_is_required(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create([
            'admin_status' => true,
        ]);

        $user = User::factory()->create([
            'admin_status' => false,
        ]);

        $attendanceRecord = $user->attendanceRecords()->create([
            'date' => '2026-10-15',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $originalBreak = $attendanceRecord->breaks()->create([
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);

        $detailPath = '/admin/attendance/'.$attendanceRecord->id;
        $postPath = '/attendance/'.$attendanceRecord->id;

        $this->actingAs($admin);

        $response = $this->from($detailPath)->post($postPath, [
            'new_clock_in' => '09:15',
            'new_clock_out' => '18:15',
            'new_break_in' => ['12:15'],
            'new_break_out' => ['13:15'],
            'comment' => '',
        ]);

        $response->assertRedirect($detailPath);
        $response->assertSessionHasErrors([
            'comment' => '備考を記入してください',
        ]);

        $this->get($detailPath)
            ->assertOk()
            ->assertSeeText('備考を記入してください');

        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '変更前の備考',
        ]);

        $this->assertSame(1, $attendanceRecord->breaks()->count());

        $originalBreak->refresh();

        $this->assertSame('12:00:00', $originalBreak->break_in);
        $this->assertSame('13:00:00', $originalBreak->break_out);

        $this->assertSame(0, $attendanceRecord->applications()->count());
    }
}
