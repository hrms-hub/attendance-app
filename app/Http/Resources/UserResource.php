<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * 公開対象のユーザーIDと名前だけを返す。
     *
     * @param  Request  $request  APIリクエスト
     * @return array<string, mixed> ユーザーの公開情報
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
