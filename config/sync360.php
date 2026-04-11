<?php

return [
    'admin_local_only' => (bool) env('SYNC360_ADMIN_LOCAL_ONLY', false),
    'host_project_root' => env('SYNC360_HOST_PROJECT_ROOT', base_path()),
    'host_port_probe_host' => env('SYNC360_HOST_PORT_PROBE_HOST', '127.0.0.1'),
    'host_port_probe_timeout_seconds' => (int) env('SYNC360_HOST_PORT_PROBE_TIMEOUT_SECONDS', 1),
    'runtime_root' => env('TENANT_RUNTIME_ROOT', base_path('runtime/tenants')),
    'template_root' => env('TENANT_TEMPLATE_ROOT', base_path('templates/tenant')),
    'infrastructure' => [
        'driver' => env('SYNC360_INFRASTRUCTURE_DRIVER', 'local'),
        'local_docker_compose_bin' => env('SYNC360_LOCAL_DOCKER_COMPOSE_BIN', 'docker compose'),
        'ssh_timeout_seconds' => (int) env('SYNC360_SSH_TIMEOUT_SECONDS', 30),
        'scp_timeout_seconds' => (int) env('SYNC360_SCP_TIMEOUT_SECONDS', 120),
        'ssh_bin' => env('SYNC360_SSH_BIN', 'ssh'),
        'scp_bin' => env('SYNC360_SCP_BIN', 'scp'),
        'sshpass_bin' => env('SYNC360_SSHPASS_BIN', 'sshpass'),
        'ssh_options' => [
            'StrictHostKeyChecking=no',
            'UserKnownHostsFile=/dev/null',
            'ConnectTimeout='.(int) env('SYNC360_SSH_CONNECT_TIMEOUT_SECONDS', 10),
        ],
    ],
    'port_range' => [
        'start' => (int) env('TENANT_PORT_RANGE_START', 4100),
        'end' => (int) env('TENANT_PORT_RANGE_END', 4199),
    ],
    'provisioning' => [
        'driver' => env('TENANT_PROVISIONING_DRIVER', 'openclaw'),
        'fake_delay_seconds' => (int) env('TENANT_PROVISIONING_DELAY_SECONDS', 3),
    ],
    'openclaw' => [
        'image' => env('OPENCLAW_IMAGE', 'ghcr.io/openclaw/openclaw:latest'),
        'service_name' => env('OPENCLAW_SERVICE_NAME', 'openclaw-gateway'),
        'compose_filename' => env('OPENCLAW_COMPOSE_FILENAME', 'compose.yaml'),
        'container_home' => env('OPENCLAW_CONTAINER_HOME', '/home/node/.openclaw'),
        'gateway_port' => (int) env('OPENCLAW_GATEWAY_INTERNAL_PORT', 18789),
        'readiness_path' => env('OPENCLAW_READINESS_PATH', '/readyz'),
        'readiness_timeout_seconds' => (int) env('OPENCLAW_READINESS_TIMEOUT_SECONDS', 45),
        'readiness_poll_interval_ms' => (int) env('OPENCLAW_READINESS_POLL_INTERVAL_MS', 1000),
        'compose_timeout_seconds' => (int) env('OPENCLAW_COMPOSE_TIMEOUT_SECONDS', 600),
    ],
    'workspace_proxy' => [
        'public_readiness_timeout_seconds' => (int) env('SYNC360_WORKSPACE_PUBLIC_READY_TIMEOUT_SECONDS', 120),
        'public_readiness_poll_interval_ms' => (int) env('SYNC360_WORKSPACE_PUBLIC_READY_POLL_INTERVAL_MS', 1500),
    ],
];
