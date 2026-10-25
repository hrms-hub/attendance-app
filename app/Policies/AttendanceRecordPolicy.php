<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;

class AttendanceRecordPolicy
{
    /**
     * 管理者は対象ユーザーにかかわらず操作を許可する。
     *
     * @param  User  $user  認証ユーザー
     * @param  string  $ability  判定する操作
     * @return bool|null 管理者はtrue、それ以外は各操作で判定
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->admin_status ? true : null;
    }

    /**
     * 本人による勤怠の更新を許可する。
     *
     * @param  User  $user  認証ユーザー
     * @param  AttendanceRecord  $attendanceRecord  対象勤怠
     */
    public function update(User $user, AttendanceRecord $attendanceRecord): bool
    {
        return (int) $user->getAuthIdentifier() === (int) $attendanceRecord->user_id;
    }

    /**
     * 本人による勤怠の削除を許可する。
     *
     * @param  User  $user  認証ユーザー
     * @param  AttendanceRecord  $attendanceRecord  対象勤怠
     */
    public function delete(User $user, AttendanceRecord $attendanceRecord): bool
    {
        return (int) $user->getAuthIdentifier() === (int) $attendanceRecord->user_id;
    }
}
