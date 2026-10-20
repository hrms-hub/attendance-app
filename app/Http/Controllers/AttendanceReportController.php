<?php

namespace App\Http\Controllers;

use App\Services\AttendanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendanceReportController extends Controller
{
    /**
     * ログイン中の一般ユーザーの勤怠レポートを表示する。
     *
     * @param  Request  $request  認証済みリクエスト
     * @param  AttendanceReportService  $reportService  勤怠統計の集計処理
     * @return View マイ勤怠レポート画面
     */
    public function index(
        Request $request,
        AttendanceReportService $reportService
    ): View {
        $user = $request->user();

        abort_if($user->admin_status, 403);

        $report = $reportService->generate(
            $user,
            CarbonImmutable::today('Asia/Tokyo')
        );

        return view('reports.index', [
            'summary' => $report['summary'],
            'monthlyTrend' => $report['monthly_trend'],
            'anomalies' => $report['anomalies'],
        ]);
    }
}
