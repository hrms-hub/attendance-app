<?php

namespace App\Http\Resources;

use App\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceRecord */
class AttendanceRecordResource extends JsonResource
{
    private bool $includeDetails = false;

    /**
     * 読み込み済みのユーザー・休憩・修正申請の内訳を公開対象にする。
     *
     * @return static 内訳を含める勤怠リソース
     */
    public function withDetails(): static
    {
        $this->includeDetails = true;

        return $this;
    }

    /**
     * 一覧と詳細で共用する勤怠のJSON構造を返す。
     *
     * @param  Request  $request  APIリクエスト
     * @return array<string, mixed> 勤怠の公開情報
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn (): string => $this->user->name),
            'date' => $this->date->format('Y-m-d'),
            'clock_in' => $this->clock_in,
            'clock_out' => $this->clock_out,
            'total_time' => $this->formatDuration($this->total_time),
            'total_break_time' => $this->formatDuration($this->total_break_time) ?? '00:00',
            'comment' => $this->comment,
            'user' => $this->when(
                $this->includeDetails,
                fn (): UserResource => new UserResource($this->whenLoaded('user'))
            ),
            'breaks' => $this->when(
                $this->includeDetails,
                fn (): AnonymousResourceCollection => AttendanceBreakResource::collection($this->whenLoaded('breaks'))
            ),
            'applications' => $this->when(
                $this->includeDetails,
                fn (): AnonymousResourceCollection => ApplicationResource::collection($this->whenLoaded('applications'))
            ),
        ];
    }

    /**
     * モデルの集計結果を分未満を切り捨てたHH:MM形式にする。
     *
     * @param  string|null  $duration  HH:MM:SS形式の集計時間
     * @return string|null HH:MM形式の集計時間。未退勤の実働時間はnull
     */
    private function formatDuration(?string $duration): ?string
    {
        return $duration === null ? null : substr($duration, 0, -3);
    }
}
