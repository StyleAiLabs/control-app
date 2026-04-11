<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('ssh_host')->nullable()->after('host');
            $table->unsignedSmallInteger('ssh_port')->default(22)->after('ssh_host');
            $table->string('ssh_user')->nullable()->after('ssh_port');
            $table->string('ssh_private_key_path')->nullable()->after('ssh_user');
            $table->string('ssh_auth_mode')->default('key')->after('ssh_private_key_path');
            $table->string('ssh_password_env_key')->nullable()->after('ssh_auth_mode');
            $table->string('sudo_password_env_key')->nullable()->after('ssh_password_env_key');
            $table->string('runtime_root')->nullable()->after('current_clients');
            $table->string('workspace_scheme')->default('http')->after('runtime_root');
            $table->string('workspace_base_domain')->nullable()->after('workspace_scheme');
            $table->string('docker_compose_bin')->default('docker compose')->after('workspace_base_domain');
            $table->string('caddy_sites_path')->nullable()->after('docker_compose_bin');
            $table->string('caddy_reload_command')->nullable()->after('caddy_sites_path');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn([
                'ssh_host',
                'ssh_port',
                'ssh_user',
                'ssh_private_key_path',
                'ssh_auth_mode',
                'ssh_password_env_key',
                'sudo_password_env_key',
                'runtime_root',
                'workspace_scheme',
                'workspace_base_domain',
                'docker_compose_bin',
                'caddy_sites_path',
                'caddy_reload_command',
            ]);
        });
    }
};
