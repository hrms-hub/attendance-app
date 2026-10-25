<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexAttendanceRecordRequest;
use App\Http\Requests\Api\V1\StoreAttendanceRecordRequest;
use App\Http\Requests\Api\V1\UpdateAttendanceRecordRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AttendanceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AttendanceRecordController extends Controller
{
    /**
     * 検索条件に合う勤怠を日付降順でページ分割して返す。
     *
     * @param  IndexAttendanceRecordRequest  $request  検証済みの検索条件
     * @return AnonymousResourceCollection 勤怠概要とページ情報
     */
    public function index(IndexAttendanceRecordRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $query = AttendanceRecord::query()
            ->with(['user', 'breaks'])
            ->when(
                $filters['user_id'] ?? null,
                fn (Builder $query, mixed $userId): Builder => $query->where('user_id', $userId)
            )
            ->when(
                $filters['date'] ?? null,
                fn (Builder $query, string $date): Builder => $query->where('date', $date)
            )
            ->when($filters['month'] ?? null, function (Builder $query, string $month): Builder {
                $start = CarbonImmutable::parse($month.'-01');

                return $query->where('date', '>=', $start->toDateString())
                    ->where('date', '<', $start->addMonth()->toDateString());
            });

        $records = $query->latest('date')
            ->orderByDesc('id')
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 20),
                page: (int) ($filters['page'] ?? 1)
            )
            ->withQueryString();

        return AttendanceRecordResource::collection($records);
    }

    /**
     * ユーザー・休憩・修正申請を含む勤怠詳細を返す。
     *
     * @param  AttendanceRecord  $attendanceRecord  URLから解決した勤怠
     * @return AttendanceRecordResource 勤怠詳細
     */
    public function show(AttendanceRecord $attendanceRecord): AttendanceRecordResource
    {
        $attendanceRecord->load(['user', 'breaks', 'applications']);

        return (new AttendanceRecordResource($attendanceRecord))->withDetails();
    }

    /**
     * 入力のuser_idを使用せず、認証ユーザー自身の勤怠を登録する。
     *
     * @param  StoreAttendanceRecordRequest  $request  検証済みの登録内容
     * @return JsonResponse 登録した勤怠とHTTP 201
     */
    public function store(StoreAttendanceRecordRequest $request): JsonResponse
    {
        $attendanceRecord = $request->user()->attendanceRecords()->create($request->validated());
        $attendanceRecord->load(['user', 'breaks']);

        return (new AttendanceRecordResource($attendanceRecord))
            ->withDetails()
            ->response()
            ->setStatusCode(201);
    }

    /**
     * 本人または管理者が送信した項目だけを更新する。
     *
     * @param  UpdateAttendanceRecordRequest  $request  検証済みの更新内容
     * @param  AttendanceRecord  $attendanceRecord  URLから解決した勤怠
     * @return AttendanceRecordResource 更新した勤怠
     */
    public function update(
        UpdateAttendanceRecordRequest $request,
        AttendanceRecord $attendanceRecord
    ): AttendanceRecordResource {
        $this->authorize('update', $attendanceRecord);

        $attendanceRecord->update($request->validated());
        $attendanceRecord->load(['user', 'breaks']);

        return (new AttendanceRecordResource($attendanceRecord))->withDetails();
    }

    /**
     * 本人または管理者が勤怠と外部キーで紐づく関連データを削除する。
     *
     * @param  AttendanceRecord  $attendanceRecord  URLから解決した勤怠
     * @return Response 本文のないHTTP 204
     */
    public function destroy(AttendanceRecord $attendanceRecord): Response
    {
        $this->authorize('delete', $attendanceRecord);

        $attendanceRecord->delete();

        return response()->noContent();
    }
}
