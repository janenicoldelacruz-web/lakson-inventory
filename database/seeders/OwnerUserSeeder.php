<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class OwnerUserSeeder extends Seeder
{
    public function run(): void
    {
        // Create default owner account
        User::updateOrCreate(
            ['email' => 'nicol@gmail.com'],
            [
                'name'     => 'Nicol Jane',
                'email'    => 'nicol@gmail.com',
                'password' => Hash::make('nicol123'),
                'role'     => 'owner',   // only if your users table has a role field
            ]
        );
    }
}
