<?php

declare(strict_types=1);

use function Hyperf\Support\env;

$csv = static fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));

return [
    'database_path' => (string) env('DATABASE_PATH', 'data/gateway.db'),
    'admin_api_key' => (string) env('ADMIN_API_KEY', 'dev-admin-key'),
    'mcp_api_key' => (string) env('MCP_API_KEY', 'dev-mcp-key'),
    'mcp_allowed_hosts' => $csv((string) env('MCP_ALLOWED_HOSTS', 'localhost,127.0.0.1,[::1]')),
    'secret_encryption_key' => (string) env('SECRET_ENCRYPTION_KEY', 'replace-with-a-long-random-secret'),
    'allow_private_targets' => filter_var(env('ALLOW_PRIVATE_TARGETS', true), FILTER_VALIDATE_BOOL),
    'mcp_allowed_origins' => $csv((string) env('MCP_ALLOWED_ORIGINS', 'http://localhost:5173')),
];
