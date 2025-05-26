<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create your first admin
        Admin::create([
            'username' => 'admin',
            'password' => Hash::make('admin123'), // Change this password!
        ]);

        echo "Admins created successfully!\n";
    }
}
