<?php

namespace Database\Seeders;

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate([
            'email' => 'admin@sync360.local',
        ], [
            'name' => 'Sync360 Super Admin',
            'phone' => null,
            'is_admin' => true,
            'password' => 'admin12345',
        ]);

        Server::query()->updateOrCreate([
            'name' => 'local-dev-server',
        ], [
            'host' => 'localhost',
            'status' => 'active',
            'max_clients' => 100,
            'current_clients' => 0,
        ]);
    }
}
