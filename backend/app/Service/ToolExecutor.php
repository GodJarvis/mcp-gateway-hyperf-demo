<?php

declare(strict_types=1);

namespace App\Service;

use App\Security\SecretCipher;
use App\Validation\SchemaValidator;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Guzzle\ClientFactory;
use RuntimeException;

final class ToolExecutor
{
    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly ConfigInterface $config,
        private readonly SecretCipher $cipher,
        private readonly SchemaValidator $schemaValidator,
    ) {
    }

    public function execute(array $tool, array $arguments): array
    {
        $started = hrtime(true);
        try {
            $this->schemaValidator->assertMatches($arguments, $tool['input_schema']);
            $this->validateTarget($tool['endpoint_url']);
            $headers = $this->buildHeaders($tool);
            $options = [
                'headers' => $headers,
                'timeout' => $tool['timeout_seconds'],
                'connect_timeout' => min(10, $tool['timeout_seconds']),
                'allow_redirects' => false,
                'http_errors' => false,
            ];
            if (in_array($tool['http_method'], ['GET', 'DELETE'], true)) {
                $options['query'] = $arguments;
            } else {
                $options['json'] = $arguments;
            }
            $response = $this->clientFactory->create()->request($tool['http_method'], $tool['endpoint_url'], $options);
            $status = $response->getStatusCode();
            $data = $this->decodeResponse((string) $response->getBody(), $response->getHeaderLine('Content-Type'));
            if ($status >= 400) {
                return $this->result($started, false, $status, $data, '目标接口返回 HTTP ' . $status);
            }
            if ($tool['output_schema'] !== null) {
                $this->schemaValidator->assertMatches($data, $tool['output_schema']);
            }
            return $this->result($started, true, $status, $data);
        } catch (GuzzleException $exception) {
            $message = str_contains(strtolower($exception->getMessage()), 'timed out')
                ? '目标接口调用超时'
                : '目标接口请求失败：' . $exception->getMessage();
            return $this->result($started, false, null, null, $message);
        } catch (\Throwable $exception) {
            return $this->result($started, false, null, null, $exception->getMessage());
        }
    }

    private function buildHeaders(array $tool): array
    {
        $headers = $tool['static_headers'];
        if ($tool['auth_type'] === 'none') {
            return $headers;
        }
        if (empty($tool['auth_secret_ciphertext'])) {
            throw new RuntimeException('工具尚未配置认证密钥');
        }
        $secret = $this->cipher->decrypt($tool['auth_secret_ciphertext']);
        $headers[$tool['auth_header_name']] = match ($tool['auth_type']) {
            'basic' => 'Basic ' . base64_encode(($tool['auth_username'] ?? '') . ':' . $secret),
            'bearer' => 'Bearer ' . $secret,
            'api_key' => $secret,
            default => throw new RuntimeException('不支持的认证类型：' . $tool['auth_type']),
        };
        return $headers;
    }

    private function validateTarget(string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            $addresses[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }
        $addresses = array_values(array_filter($addresses));
        if ($addresses === [] && filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        }
        if ($addresses === []) {
            throw new RuntimeException('目标域名无法解析');
        }
        $allowPrivate = (bool) $this->config->get('gateway.allow_private_targets', true);
        foreach ($addresses as $address) {
            $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if (! $allowPrivate && $public === false) {
                throw new RuntimeException('当前配置禁止访问私有或保留网络地址');
            }
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                && ! $allowPrivate) {
                throw new RuntimeException('目标地址解析到不允许访问的地址');
            }
        }
    }

    private function decodeResponse(string $body, string $contentType): mixed
    {
        if (str_contains(strtolower($contentType), 'json')) {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        }
        $decoded = json_decode($body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
    }

    private function result(int $started, bool $success, ?int $status, mixed $data = null, ?string $error = null): array
    {
        return [
            'success' => $success,
            'http_status' => $status,
            'duration_ms' => max(0, (int) round((hrtime(true) - $started) / 1_000_000)),
            'data' => $data,
            'error' => $error,
        ];
    }
}
