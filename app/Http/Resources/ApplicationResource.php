<?php

namespace App\Http\Resources;

use App\Models\AttendanceCorrectRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceCorrectRequest */
class ApplicationResource extends JsonResource
{
    /**
     * 修正申請の内容と承認状態を返す。
     *
     * @param  Request  $request  APIリクエスト
     * @return array<string, mixed> 修正申請の公開情報
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'new_date' => $this->new_date->format('Y-m-d'),
            'new_clock_in' => $this->new_clock_in,
            'new_clock_out' => $this->new_clock_out,
            'comment' => $this->comment,
            'approval_status' => $this->approval_status,
            'application_date' => $this->application_date->format('Y-m-d'),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
        ];
    }
}
