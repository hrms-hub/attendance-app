<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'password123';

    public function run(): void
    {
        $seed_users = [
            [
                'name' => '一般ユーザー',
                'email' => 'user@example.com',
                'admin_status' => false,
            ],
            [
                'name' => '管理者',
                'email' => 'admin@example.com',
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
            $user->save();
        }
    }
}