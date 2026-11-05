<?php

declare(strict_types=1);

namespace App\Validation;

use RuntimeException;

final class PayloadValidator
{
    public function __construct(private readonly SchemaValidator $schemaValidator)
    {
    }

    public function system(array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        $code = trim((string) ($body['code'] ?? ''));
        $description = (string) ($body['description'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new RuntimeException('系统名称长度必须为 1-100 个字符', 422);
        }
        if (! preg_match('/^[a-z][a-z0-9_-]{1,49}$/', $code)) {
            throw new RuntimeException('系统编码必须以小写字母开头，仅含小写字母、数字、_、-', 422);
        }
        if (mb_strlen($description) > 2000) {
            throw new RuntimeException('系统描述不能超过 2000 个字符', 422);
        }
        return ['name' => $name, 'code' => $code, 'description' => $description, 'enabled' => (int) ($body['enabled'] ?? true)];
    }

    public function tool(array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $endpoint = trim((string) ($body['endpoint_url'] ?? ''));
        $method = strtoupper((string) ($body['http_method'] ?? 'POST'));
        $timeout = (int) ($body['timeout_seconds'] ?? 30);
        $input = $body['input_schema'] ?? ['type' => 'object', 'properties' => []];
        $output = $body['output_schema'] ?? null;
        $headers = $body['static_headers'] ?? [];
        $authType = (string) ($body['auth_type'] ?? 'none');
        $authHeader = trim((string) ($body['auth_header_name'] ?? 'Authorization'));
        $authUsername = isset($body['auth_username']) ? trim((string) $body['auth_username']) : null;

        if (! preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/', $name)) {
            throw new RuntimeException('工具名称格式不正确', 422);
        }
        if ($description === '' || mb_strlen($description) > 4000) {
            throw new RuntimeException('工具描述长度必须为 1-4000 个字符', 422);
        }
        $parts = parse_url($endpoint);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('端点地址必须是有效且不含用户凭证的 http 或 https URL', 422);
        }
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) || $timeout < 1 || $timeout > 300) {
            throw new RuntimeException('HTTP 方法或超时时间不合法', 422);
        }
        if (! is_array($input) || ($output !== null && ! is_array($output)) || ! is_array($headers)) {
            throw new RuntimeException('Schema 和静态 Header 必须是 JSON 对象', 422);
        }
        $this->schemaValidator->assertSchema($input, '入参 Schema ');
        if ($output !== null && $output !== []) {
            $this->schemaValidator->assertSchema($output, '出参 Schema ');
        } else {
            $output = null;
        }
        foreach ($headers as $header => $value) {
            if (! is_string($header) || ! is_string($value) || in_array(strtolower($header), ['host', 'content-length'], true)) {
                throw new RuntimeException('静态 Header 不合法，且不能设置 Host 或 Content-Length', 422);
            }
        }
        if (! in_array($authType, ['none', 'basic', 'bearer', 'api_key'], true)) {
            throw new RuntimeException('不支持的认证类型', 422);
        }
        if ($authType !== 'none' && $authHeader === '') {
            throw new RuntimeException('启用认证时必须指定 Header 名称', 422);
        }
        if ($authType === 'basic' && $authUsername === '') {
            throw new RuntimeException('Basic 认证必须填写用户名', 422);
        }
        return [
            'business_system_id' => (int) ($body['business_system_id'] ?? 0), 'name' => $name,
            'description' => $description, 'endpoint_url' => $endpoint, 'http_method' => $method,
            'timeout_seconds' => $timeout, 'input_schema' => $input, 'output_schema' => $output,
            'static_headers' => $headers, 'auth_type' => $authType, 'auth_header_name' => $authHeader,
            'auth_username' => $authType === 'none' ? null : $authUsername, 'enabled' => (bool) ($body['enabled'] ?? true),
            'auth_secret' => isset($body['auth_secret']) ? (string) $body['auth_secret'] : null,
        ];
    }
}
