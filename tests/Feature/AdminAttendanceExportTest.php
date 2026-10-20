<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class AdminAttendanceExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->admin = User::factory()->create([
            'admin_status' => true,
        ]);
    }

    public function test_admin_can_download_selected_staff_monthly_attendance(): void
    {
        // 準備：対象スタッフと別スタッフを作成する
        $staff = User::factory()->create([
            'admin_status' => false,
        ]);

        $otherStaff = User::factory()->create([
            'admin_status' => false,
        ]);

        // 日付順に出力されることを確認するため、後の日付から作る
        $this->createAttendance(
            $staff,
            '2026-10-19',
            '09:30:00',
            '18:00:00'
        );

        $attendance = $this->createAttendance($staff, '2026-10-05');

        // 複数休憩の合計は1時間、実働は8時間
        $attendance->breaks()->createMany([
            [
                'break_in' => '12:00:00',
                'break_out' => '12:30:00',
            ],
            [
                'break_in' => '15:00:00',
                'break_out' => '15:30:00',
            ],
        ]);

        // 対象外の月・スタッフの勤怠も用意する
        $this->createAttendance($staff, '2026-09-30');
        $this->createAttendance($staff, '2026-11-01');
        $this->createAttendance($otherStaff, '2026-10-05');

        // 実行：対象スタッフの2026年10月を出力する
        $response = $this->actingAs($this->admin, 'web')
            ->post('/export', [
                'user_id' => $staff->id,
                'year_month' => '2026-10',
            ]);

        // 検証：ファイル名・形式・CSV全行が正しい
        $response->assertOk();
        $response->assertDownload(
            'attendance_'.$staff->id.'_2026-10.csv'
        );
        $response->assertHeader(
            'Content-Type',
            'text/csv; charset=UTF-8'
        );

        $this->assertSame([
            ['日付', '出勤', '退勤', '休憩', '合計'],
            ['2026-10-05', '09:00', '18:00', '1:00', '8:00'],
            ['2026-10-19', '09:30', '18:00', '', '8:30'],
        ], $this->csvRows($response));
    }

    public function test_month_without_attendance_exports_header_only(): void
    {
        $staff = User::factory()->create([
            'admin_status' => false,
        ]);

        $response = $this->actingAs($this->admin, 'web')
            ->post('/export', [
                'user_id' => $staff->id,
                'year_month' => '2026-10',
            ]);

        $response->assertOk();

        $this->assertSame([
            ['日付', '出勤', '退勤', '休憩', '合計'],
        ], $this->csvRows($response));
    }

    public function test_unfinished_attendance_has_blank_clock_out_and_total(): void
    {
        $staff = User::factory()->create([
            'admin_status' => false,
        ]);

        $attendance = $this->createAttendance(
            $staff,
            '2026-10-20',
            '09:00:00',
            null
        );

        // 終了済み休憩だけが休憩合計に含まれる
        $attendance->breaks()->createMany([
            [
                'break_in' => '12:00:00',
                'break_out' => '13:00:00',
            ],
            [
                'break_in' => '15:00:00',
                'break_out' => null,
            ],
        ]);

        $response = $this->actingAs($this->admin, 'web')
            ->post('/export', [
                'user_id' => $staff->id,
                'year_month' => '2026-10',
            ]);

        $response->assertOk();

        $this->assertSame([
            ['日付', '出勤', '退勤', '休憩', '合計'],
            ['2026-10-20', '09:00', '', '1:00', ''],
        ], $this->csvRows($response));
    }

    public function test_staff_and_month_are_required(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->from('/admin/staff/list')
            ->post('/export', []);

        $response->assertRedirect('/admin/staff/list');
        $response->assertSessionHasErrors([
            'user_id' => 'スタッフを指定してください。',
            'year_month' => '対象月を指定してください。',
        ]);
    }

    public function test_nonexistent_staff_is_rejected(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->from('/admin/staff/list')
            ->post('/export', [
                'user_id' => 999999,
                'year_month' => '2026-10',
            ]);

        $response->assertRedirect('/admin/staff/list');
        $response->assertSessionHasErrors([
            'user_id' => '対象のスタッフが存在しません。',
        ]);
    }

    public function test_admin_cannot_be_selected_as_export_target(): void
    {
        $response = $this->actingAs($this->admin, 'web')
            ->from('/admin/staff/list')
            ->post('/export', [
                'user_id' => $this->admin->id,
                'year_month' => '2026-10',
            ]);

        $response->assertRedirect('/admin/staff/list');
        $response->assertSessionHasErrors([
            'user_id' => '対象のスタッフが存在しません。',
        ]);
    }

    public function test_invalid_month_is_rejected(): void
    {
        $staff = User::factory()->create([
            'admin_status' => false,
        ]);

        $response = $this->actingAs($this->admin, 'web')
            ->from('/admin/staff/list')
            ->post('/export', [
                'user_id' => $staff->id,
                'year_month' => '2026-13',
            ]);

        $response->assertRedirect('/admin/staff/list');
        $response->assertSessionHasErrors([
            'year_month' => '対象月はYYYY-MM形式で指定してください。',
        ]);
    }

    public function test_guest_cannot_download_csv(): void
    {
        $staff = User::factory()->create([
            'admin_status' => false,
        ]);

        // 未ログインの状態でCSV出力を要求する
        $response = $this->post('/export', [
            'user_id' => $staff->id,
            'year_month' => '2026-10',
        ]);

        $response->assertRedirect('/login');
        $this->assertGuest('web');
    }

    public function test_general_user_cannot_download_csv(): void
    {
        $staff = User::factory()->create([
            'admin_status' => false,
        ]);

        // 一般ユーザーは自分の勤怠でもCSV出力できない
        $response = $this->actingAs($staff, 'web')
            ->post('/export', [
                'user_id' => $staff->id,
                'year_month' => '2026-10',
            ]);

        $response->assertForbidden();
        $this->assertAuthenticatedAs($staff, 'web');
    }

    /**
     * 指定ユーザーの勤怠を作成する。
     */
    private function createAttendance(
        User $user,
        string $date,
        string $clockIn = '09:00:00',
        ?string $clockOut = '18:00:00'
    ): AttendanceRecord {
        return $user->attendanceRecords()->create([
            'date' => $date,
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
        ]);
    }

    /**
     * BOMを確認し、ダウンロードされたCSVを行配列に変換する。
     *
     * @return array<int, array<int, string|null>>
     */
    private function csvRows(TestResponse $response): array
    {
        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('テスト用CSVを開けませんでした。');
        }

        try {
            fwrite($stream, substr($content, 3));
            rewind($stream);

            $rows = [];

            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }
}
