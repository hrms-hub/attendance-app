<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'password';

    public function run(): void
    {
        $seed_users = [
            [
                'name' => '一般ユーザー1',
                'email' => 'user1@example.com',
                'admin_status' => false,
            ],
            [
                'name' => '一般ユーザー2',
                'email' => 'user2@example.com',
                'admin_status' => false,
            ],
            [
                'name' => '管理者ユーザー',
                'email' => 'user3@example.com',
                'admin_status' => true,
            ],
        ];

        foreach ($seed_users as $seed_user) {
            $user = User::firstOrNew([
                'email' => $seed_user['email'],
            ]);

            $user->name = $seed_user['name'];
            $user->password = Hash::make(self::DEFAULT_PASSWORD);
            $user->admin_status = $seed_user['admin_status'];
            $user->email_verified_at ??= now();
            $user->save();
        }
    }
}
