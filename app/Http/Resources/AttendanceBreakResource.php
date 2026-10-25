<?php

namespace App\Http\Resources;

use App\Models\BreakRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BreakRecord */
class AttendanceBreakResource extends JsonResource
{
    /**
     * 休憩のIDと開始・終了時刻を返す。
     *
     * @param  Request  $request  APIリクエスト
     * @return array<string, mixed> 休憩の公開情報
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'break_in' => $this->break_in,
            'break_out' => $this->break_out,
        ];
    }
}
