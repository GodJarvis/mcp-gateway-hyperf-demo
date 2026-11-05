<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Repository\GatewayRepository;
use App\Service\ToolExecutor;
use App\Validation\SchemaValidator;
use JsonException;
use Throwable;

final class ProtocolHandler
{
    public const MODERN_PROTOCOL_VERSION = '2026-07-28';
    public const LEGACY_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly GatewayRepository $repository,
        private readonly ToolExecutor $executor,
        private readonly SchemaValidator $schemaValidator,
    ) {
    }

    public function handleJson(string $json): ?string
    {
        try {
            $message = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($message)) {
                throw new ProtocolException(-32600, 'Invalid Request');
            }
        } catch (JsonException) {
            return $this->encode($this->error(null, -32700, 'Parse error'));
        }
        $id = $message['id'] ?? null;
        $notification = ! array_key_exists('id', $message);
        try {
            $response = $this->handle($message);
            return $response === null ? null : $this->encode($response);
        } catch (ProtocolException $exception) {
            return $notification ? null : $this->encode($this->error($id, $exception->getCode(), $exception->getMessage(), $exception->errorData));
        } catch (Throwable) {
            return $notification ? null : $this->encode($this->error($id, -32603, 'Internal error'));
        }
    }

    public function handle(array $message): ?array
    {
        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            throw new ProtocolException(-32600, 'Invalid Request');
        }
        if (! array_key_exists('id', $message)) {
            return null;
        }
        $params = $message['params'] ?? [];
        if (! is_array($params)) {
            throw new ProtocolException(-32602, 'Invalid params');
        }
        $method = $message['method'];
        $modern = $this->isModernRequest($method, $params);
        $result = match ($method) {
            'server/discover' => $this->discover(),
            'initialize' => $this->initialize($params),
            'ping' => [],
            'tools/list' => $this->listTools($modern),
            'tools/call' => $this->callTool($params, $modern),
            default => throw new ProtocolException(-32601, 'Method not found'),
        };
        return ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result];
    }

    public function isModernMessage(array $message): bool
    {
        return isset($message['params']['_meta']['io.modelcontextprotocol/protocolVersion']);
    }

    private function isModernRequest(string $method, array $params): bool
    {
        if ($method === 'initialize') {
            return false;
        }
        $version = $params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null;
        if ($version === null) {
            if ($method === 'server/discover') {
                throw new ProtocolException(-32602, 'Modern request metadata is required');
            }
            return false;
        }
        if ($version !== self::MODERN_PROTOCOL_VERSION) {
            throw new ProtocolException(-32022, 'Unsupported protocol version', ['supported' => [self::MODERN_PROTOCOL_VERSION], 'requested' => $version]);
        }
        if (! is_array($params['_meta']['io.modelcontextprotocol/clientCapabilities'] ?? null)) {
            throw new ProtocolException(-32602, 'Client capabilities metadata is required');
        }
        return true;
    }

    private function discover(): array
    {
        return [
            'resultType' => 'complete', 'supportedVersions' => [self::MODERN_PROTOCOL_VERSION],
            'capabilities' => ['tools' => ['listChanged' => true]],
            'instructions' => '通过 Gateway 动态调用已启用的业务 HTTP 工具。',
            '_meta' => ['io.modelcontextprotocol/serverInfo' => $this->serverInfo()],
            'ttlMs' => 60000, 'cacheScope' => 'private',
        ];
    }

    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? self::LEGACY_PROTOCOL_VERSIONS[0];
        $version = is_string($requested) && in_array($requested, self::LEGACY_PROTOCOL_VERSIONS, true) ? $requested : self::LEGACY_PROTOCOL_VERSIONS[0];
        return [
            'protocolVersion' => $version, 'capabilities' => ['tools' => ['listChanged' => true]],
            'serverInfo' => $this->serverInfo(), 'instructions' => '通过 Gateway 动态调用已启用的业务 HTTP 工具。',
        ];
    }

    private function listTools(bool $modern): array
    {
        $tools = array_map(fn (array $tool): array => array_filter([
            'name' => $tool['name'], 'description' => $tool['description'],
            'inputSchema' => $this->schemaValidator->present($tool['input_schema']),
            'outputSchema' => $tool['output_schema'] === null ? null : $this->schemaValidator->present($tool['output_schema']),
        ], static fn (mixed $value): bool => $value !== null), $this->repository->listTools(true));
        $result = ['tools' => $tools];
        return $modern ? ['resultType' => 'complete', ...$result, 'ttlMs' => 60000, 'cacheScope' => 'private'] : $result;
    }

    private function callTool(array $params, bool $modern): array
    {
        $name = $params['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new ProtocolException(-32602, 'Tool name is required');
        }
        $arguments = $params['arguments'] ?? [];
        if (! is_array($arguments)) {
            throw new ProtocolException(-32602, 'Tool arguments must be an object');
        }
        try {
            $tool = $this->repository->findActiveToolByName($name);
        } catch (\RuntimeException $exception) {
            $result = $this->errorResult($exception->getMessage());
            return $modern ? ['resultType' => 'complete', ...$result] : $result;
        }
        $execution = $this->executor->execute($tool, $arguments);
        $keys = array_keys($arguments);
        sort($keys);
        $this->repository->addAudit([
            'tool_id' => $tool['id'], 'tool_name' => $tool['name'], 'source' => 'mcp',
            'success' => $execution['success'], 'http_status' => $execution['http_status'],
            'duration_ms' => $execution['duration_ms'], 'argument_keys' => $keys, 'error_message' => $execution['error'],
        ]);
        if (! $execution['success']) {
            $detail = $execution['error'] ?? '工具调用失败';
            if ($execution['data'] !== null) {
                $detail .= "\n" . $this->toText($execution['data']);
            }
            $result = $this->errorResult($detail);
        } else {
            $result = [
                'content' => [['type' => 'text', 'text' => $this->toText($execution['data'])]],
                'isError' => false,
            ];
            if (is_array($execution['data']) && ! array_is_list($execution['data'])) {
                $result['structuredContent'] = $execution['data'];
            }
        }
        return $modern ? ['resultType' => 'complete', ...$result] : $result;
    }

    private function errorResult(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    private function serverInfo(): array
    {
        return ['name' => 'enterprise-mcp-gateway-hyperf', 'title' => 'Hyperf 动态 MCP Gateway', 'version' => '1.0.0'];
    }

    private function error(mixed $id, int $code, string $message, mixed $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    private function toText(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, self::JSON_FLAGS | JSON_PRETTY_PRINT);
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }
}
