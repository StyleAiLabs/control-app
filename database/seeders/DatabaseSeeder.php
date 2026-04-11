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
            'name' => env('SYNC360_DEFAULT_SERVER_NAME', 'sync360-client-vps-1'),
        ], [
            'host' => env('SYNC360_DEFAULT_SERVER_HOST', '89.116.28.191'),
            'ssh_host' => env('SYNC360_DEFAULT_SERVER_SSH_HOST', env('SYNC360_DEFAULT_SERVER_HOST', '89.116.28.191')),
            'ssh_port' => (int) env('SYNC360_DEFAULT_SERVER_SSH_PORT', 22),
            'ssh_user' => env('SYNC360_DEFAULT_SERVER_SSH_USER', 'deploy'),
            'ssh_private_key_path' => env('SYNC360_DEFAULT_SERVER_SSH_PRIVATE_KEY_PATH'),
            'ssh_auth_mode' => env('SYNC360_DEFAULT_SERVER_SSH_AUTH_MODE', 'password'),
            'ssh_password_env_key' => env('SYNC360_DEFAULT_SERVER_SSH_PASSWORD_ENV_KEY', 'SYNC360_CLIENT_VPS_SSH_PASSWORD'),
            'sudo_password_env_key' => env('SYNC360_DEFAULT_SERVER_SUDO_PASSWORD_ENV_KEY', 'SYNC360_CLIENT_VPS_SUDO_PASSWORD'),
            'status' => 'active',
            'max_clients' => 100,
            'current_clients' => 0,
            'runtime_root' => env('SYNC360_DEFAULT_SERVER_RUNTIME_ROOT', '/srv/sync360/runtime'),
            'workspace_scheme' => env('SYNC360_DEFAULT_SERVER_WORKSPACE_SCHEME', 'https'),
            'workspace_base_domain' => env('SYNC360_DEFAULT_SERVER_WORKSPACE_BASE_DOMAIN', 'workspace.sync360.co.nz'),
            'docker_compose_bin' => env('SYNC360_DEFAULT_SERVER_DOCKER_COMPOSE_BIN', 'docker compose'),
            'caddy_sites_path' => env('SYNC360_DEFAULT_SERVER_CADDY_SITES_PATH', '/etc/caddy/sites'),
            'caddy_reload_command' => env('SYNC360_DEFAULT_SERVER_CADDY_RELOAD_COMMAND', 'systemctl reload caddy'),
        ]);
    }
}
