<?php

declare(strict_types=1);

use GuzzleHttp\Client;

require dirname(__DIR__) . '/vendor/autoload.php';
$base = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:19501';
$http = new Client(['base_uri' => $base, 'http_errors' => false, 'timeout' => 10]);
$assert = static function (bool $value, string $message): void {
    if (! $value) {
        throw new RuntimeException($message);
    }
};
$admin = ['Authorization' => 'Bearer ' . (getenv('ADMIN_API_KEY') ?: 'dev-admin-key')];
$mcp = ['Authorization' => 'Bearer ' . (getenv('MCP_API_KEY') ?: 'dev-mcp-key'), 'Accept' => 'application/json, text/event-stream'];
$rpc = static function (string $method, array $params, bool $modern = true, array $extra = []) use ($http, $mcp) {
    $headers = $mcp + $extra;
    if ($modern) {
        $params['_meta'] = ($params['_meta'] ?? []) + [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];
        $headers += ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
        if ($method === 'tools/call') {
            $headers['Mcp-Name'] = $params['name'];
        }
    }
    return $http->post('/mcp', ['headers' => $headers, 'stream' => $method === 'tools/call', 'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]]);
};
$decode = static function ($response): array {
    $body = (string) $response->getBody();
    if (str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
        preg_match_all('/^data: (.+)$/m', $body, $matches);
        return array_map(static fn ($value) => json_decode($value, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
    }
    return [json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
};
$systemId = $toolId = null;
try {
    $response = $http->get('/mcp');
    $assert($response->getStatusCode() === 401, 'MCP token required');
    $response = $http->options('/mcp', ['headers' => ['Origin' => 'http://localhost:5173', 'Access-Control-Request-Method' => 'POST']]);
    $assert($response->getStatusCode() === 204, 'OPTIONS preflight');
    $response = $http->post('/mcp', ['headers' => $mcp + ['Origin' => 'https://untrusted.invalid'], 'json' => (object) []]);
    $assert($response->getStatusCode() === 403, 'SDK Origin rejection');
    $response = $http->post('/mcp', ['headers' => $mcp + ['Host' => 'untrusted.invalid'], 'json' => (object) []]);
    $assert($response->getStatusCode() === 403, 'SDK Host rejection');
    $response = $http->post('/api/systems', ['headers' => $admin, 'json' => ['name' => 'SDK test ' . uniqid(), 'code' => 'sdk_' . uniqid()]]);
    $systemId = json_decode((string) $response->getBody(), true)['id'];
    $toolName = 'sdk_health_' . uniqid();
    $response = $http->post('/api/tools', ['headers' => $admin, 'json' => [
        'business_system_id' => $systemId, 'name' => $toolName, 'description' => 'SDK integration test',
        'endpoint_url' => getenv('TEST_TARGET_URL') ?: $base . '/api/health', 'http_method' => 'GET',
        'input_schema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
        'output_schema' => ['type' => 'object', 'required' => ['status']],
        'static_headers' => getenv('TEST_TARGET_URL') ? ['X-Test-Delay-Ms' => '800'] : (object) [],
    ]]);
    $assert($response->getStatusCode() === 201, 'Create dynamic tool');
    $toolId = json_decode((string) $response->getBody(), true)['id'];
    $list = $decode($rpc('tools/list', []))[0];
    $assert(in_array($toolName, array_column($list['result']['tools'], 'name'), true), 'Modern dynamic list');
    $started = microtime(true);
    $response = $rpc('tools/call', ['name' => $toolName, 'arguments' => (object) [], '_meta' => ['progressToken' => 'integration']]);
    $firstByte = $response->getBody()->read(1);
    $firstByteTime = microtime(true) - $started;
    $response = $response->withBody(\GuzzleHttp\Psr7\Utils::streamFor($firstByte . $response->getBody()->getContents()));
    if (getenv('TEST_TARGET_URL')) {
        $assert($firstByteTime < 0.6 && microtime(true) - $started > 0.65, 'SSE starts before delayed target completes');
        echo 'PASS real SSE timing first_byte=' . round($firstByteTime, 3) . 's total=' . round(microtime(true) - $started, 3) . "s\n";
    }
    $frames = $decode($response);
    $assert(str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream'), 'SDK SSE content type');
    $assert(count($frames) >= 3 && $frames[0]['method'] === 'notifications/progress', 'SDK progress frames');
    $last = $frames[array_key_last($frames)];
    $assert(($last['result']['structuredContent']['status'] ?? null) === 'ok', 'Final SSE result');
    $init = $rpc('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'integration', 'version' => '1']], false);
    $session = $init->getHeaderLine('Mcp-Session-Id');
    $assert($session !== '', 'SDK persistent session');
    $legacyHeaders = ['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '2025-11-25'];
    $http->post('/mcp', ['headers' => $mcp + $legacyHeaders, 'json' => ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']]);
    $legacy = $decode($rpc('tools/call', ['name' => $toolName, 'arguments' => (object) [], '_meta' => ['progressToken' => 'legacy']], false, $legacyHeaders));
    $assert(($legacy[array_key_last($legacy)]['result']['structuredContent']['status'] ?? null) === 'ok', 'Legacy session call');
    $http->patch('/api/tools/' . $toolId . '/status', ['headers' => $admin, 'json' => ['enabled' => false]]);
    $list = $decode($rpc('tools/list', []))[0];
    $assert(! in_array($toolName, array_column($list['result']['tools'], 'name'), true), 'Disable immediately visible');
    $response = $http->delete('/mcp', ['headers' => $mcp + $legacyHeaders]);
    $assert($response->getStatusCode() === 200, 'DELETE session');
    $logs = json_decode((string) $http->get('/api/audit-logs', ['headers' => $admin])->getBody(), true);
    $assert(count(array_filter($logs, static fn ($log) => $log['tool_name'] === $toolName && $log['success'])) === 2, 'MCP audit');
    echo "PASS SDK auth, OPTIONS, dynamic registry, modern/legacy calls, SSE progress/final frames, sessions, hot update, audit\n";
} finally {
    if ($toolId !== null) {
        $http->delete('/api/tools/' . $toolId, ['headers' => $admin]);
    }
    if ($systemId !== null) {
        $http->delete('/api/systems/' . $systemId, ['headers' => $admin]);
    }
}
