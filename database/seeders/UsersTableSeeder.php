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
        $seedUsers = [
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

        foreach ($seedUsers as $seedUser) {
            $user = User::firstOrNew([
                'email' => $seedUser['email'],
            ]);

            $user->name = $seedUser['name'];
            $user->password = Hash::make(self::DEFAULT_PASSWORD);
            $user->admin_status = $seedUser['admin_status'];
            $user->email_verified_at ??= now();
            $user->save();
        }
    }
}
