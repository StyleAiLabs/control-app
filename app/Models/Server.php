<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'host',
        'ssh_host',
        'ssh_port',
        'ssh_user',
        'ssh_private_key_path',
        'ssh_auth_mode',
        'ssh_password_env_key',
        'sudo_password_env_key',
        'status',
        'max_clients',
        'current_clients',
        'runtime_root',
        'workspace_scheme',
        'workspace_base_domain',
        'docker_compose_bin',
        'caddy_sites_path',
        'caddy_reload_command',
    ];

    protected function casts(): array
    {
        return [
            'ssh_port' => 'integer',
            'max_clients' => 'integer',
            'current_clients' => 'integer',
        ];
    }

    public function usesPasswordAuth(): bool
    {
        return $this->ssh_auth_mode === 'password';
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
