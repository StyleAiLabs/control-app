<?php

return [
    'host_project_root' => env('SYNC360_HOST_PROJECT_ROOT', base_path()),
    'host_port_probe_host' => env('SYNC360_HOST_PORT_PROBE_HOST', 'host.docker.internal'),
    'host_port_probe_timeout_seconds' => (int) env('SYNC360_HOST_PORT_PROBE_TIMEOUT_SECONDS', 1),
    'runtime_root' => env('TENANT_RUNTIME_ROOT', base_path('runtime/tenants')),
    'template_root' => env('TENANT_TEMPLATE_ROOT', base_path('templates/tenant')),
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
        'readiness_probe_host' => env('OPENCLAW_READINESS_PROBE_HOST', 'host.docker.internal'),
        'readiness_timeout_seconds' => (int) env('OPENCLAW_READINESS_TIMEOUT_SECONDS', 45),
        'readiness_poll_interval_ms' => (int) env('OPENCLAW_READINESS_POLL_INTERVAL_MS', 1000),
        'compose_timeout_seconds' => (int) env('OPENCLAW_COMPOSE_TIMEOUT_SECONDS', 600),
    ],
];
