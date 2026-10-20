<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceExportRequest;
use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceExportController extends Controller
{
    /**
     * 指定スタッフの月別勤怠をCSVでダウンロードする。
     *
     * @param  AttendanceExportRequest  $request  出力対象のスタッフと月
     * @return StreamedResponse CSVのダウンロードレスポンス
     */
    public function __invoke(AttendanceExportRequest $request): StreamedResponse
    {
        $validated = $request->validated();

        $user = User::query()
            ->where('admin_status', false)
            ->findOrFail((int) $validated['user_id']);

        $date = CarbonImmutable::parse(
            $validated['year_month'].'-01',
            'Asia/Tokyo'
        );

        $attendanceRecords = $user->attendanceRecords()
            ->with('breaks')
            ->whereBetween('date', [
                $date->toDateString(),
                $date->endOfMonth()->toDateString(),
            ])
            ->orderBy('date')
            ->get();

        $rows = $attendanceRecords
            ->map(fn (AttendanceRecord $attendanceRecord): array => [
                $attendanceRecord->date->format('Y-m-d'),
                substr($attendanceRecord->clock_in, 0, 5),
                substr($attendanceRecord->clock_out ?? '', 0, 5),
                $this->formatDuration($attendanceRecord->total_break_time),
                $this->formatDuration($attendanceRecord->total_time),
            ])
            ->prepend(['日付', '出勤', '退勤', '休憩', '合計']);

        $filename = sprintf(
            'attendance_%d_%s.csv',
            $user->id,
            $date->format('Y-m')
        );

        return response()->streamDownload(
            function () use ($rows): void {
                $output = fopen('php://output', 'w');

                if ($output === false) {
                    throw new RuntimeException('CSVの出力を開始できませんでした。');
                }

                try {
                    // Excelで日本語を読み込めるようにUTF-8 BOMを付ける
                    fwrite($output, "\xEF\xBB\xBF");

                    $rows->each(function (array $row) use ($output): void {
                        fputcsv($output, $row, ',', '"', '', "\r\n");
                    });
                } finally {
                    fclose($output);
                }
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    /**
     * モデルの経過時間を一覧表示と同じ「時間:分」に整える。
     *
     * @param  string|null  $duration  HH:MM:SS形式の経過時間
     * @return string 未確定の場合は空文字
     */
    private function formatDuration(?string $duration): string
    {
        if ($duration === null) {
            return '';
        }

        [$hours, $minutes] = explode(':', $duration);

        return sprintf('%d:%02d', (int) $hours, (int) $minutes);
    }
}
