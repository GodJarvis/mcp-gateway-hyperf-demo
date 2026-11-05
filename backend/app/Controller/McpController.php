<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mcp\ProtocolHandler;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponse;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class McpController
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly ProtocolHandler $handler,
        private readonly HyperfResponse $response,
        private readonly ConfigInterface $config,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! hash_equals('Bearer ' . (string) $this->config->get('gateway.mcp_api_key'), $request->getHeaderLine('Authorization'))) {
            return $this->jsonRpcError(null, -32001, 'Unauthorized', 401);
        }
        $origin = $request->getHeaderLine('Origin');
        $allowedOrigins = $this->config->get('gateway.mcp_allowed_origins', []);
        if ($origin !== '' && ! in_array('*', $allowedOrigins, true) && ! in_array($origin, $allowedOrigins, true)) {
            return $this->jsonRpcError(null, -32600, 'Forbidden Origin', 403);
        }
        if (! str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            return $this->jsonRpcError(null, -32600, 'Content-Type must be application/json', 415);
        }
        $accept = strtolower($request->getHeaderLine('Accept'));
        if (! str_contains($accept, 'application/json') || ! str_contains($accept, 'text/event-stream')) {
            return $this->jsonRpcError(null, -32600, 'Accept must include application/json and text/event-stream', 406);
        }
        $raw = (string) $request->getBody();
        try {
            $message = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($message)) {
                throw new JsonException();
            }
        } catch (JsonException) {
            return $this->jsonRpcError(null, -32700, 'Parse error', 400);
        }
        $metadataError = $this->validateMetadata($request, $message);
        if ($metadataError !== null) {
            return $metadataError;
        }
        $responseJson = $this->handler->handleJson($raw);
        if ($responseJson === null) {
            return $this->response->raw('')->withStatus(202)->withoutHeader('Content-Type');
        }
        $decoded = json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR);
        $errorCode = $decoded['error']['code'] ?? null;
        if (in_array($errorCode, [-32700, -32600, -32020, -32022], true)) {
            return $this->response->json($decoded)->withStatus(400)->withHeader('Cache-Control', 'no-store');
        }
        $events = [];
        $progressToken = $message['params']['_meta']['progressToken'] ?? null;
        if (($message['method'] ?? null) === 'tools/call' && (is_string($progressToken) || is_int($progressToken))) {
            $events[] = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => [
                'progressToken' => $progressToken, 'progress' => 1, 'total' => 1, 'message' => '工具调用完成',
            ]], self::JSON_FLAGS);
        }
        $events[] = $responseJson;
        return $this->sse($events);
    }

    private function validateMetadata(ServerRequestInterface $request, array $message): ?ResponseInterface
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $bodyVersion = $message['params']['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null;
        $headerVersion = $request->getHeaderLine('MCP-Protocol-Version');
        if ($bodyVersion !== null || $headerVersion === ProtocolHandler::MODERN_PROTOCOL_VERSION) {
            if (! is_string($bodyVersion) || $headerVersion !== $bodyVersion) {
                return $this->jsonRpcError($id, -32020, 'Header mismatch: MCP-Protocol-Version', 400);
            }
            if ($request->getHeaderLine('Mcp-Method') !== $method) {
                return $this->jsonRpcError($id, -32020, 'Header mismatch: Mcp-Method', 400);
            }
            if ($method === 'tools/call' && $request->getHeaderLine('Mcp-Name') !== ($message['params']['name'] ?? null)) {
                return $this->jsonRpcError($id, -32020, 'Header mismatch: Mcp-Name', 400);
            }
        }
        if ($method !== 'initialize' && $headerVersion !== '' && ! in_array($headerVersion, [ProtocolHandler::MODERN_PROTOCOL_VERSION, ...ProtocolHandler::LEGACY_PROTOCOL_VERSIONS], true)) {
            return $this->jsonRpcError($id, -32022, 'Unsupported protocol version', 400);
        }
        return null;
    }

    private function jsonRpcError(mixed $id, int $code, string $message, int $status): ResponseInterface
    {
        return $this->response->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]])
            ->withStatus($status)->withHeader('Cache-Control', 'no-store');
    }

    private function sse(array $events): ResponseInterface
    {
        $body = '';
        foreach ($events as $event) {
            $body .= "event: message\n";
            foreach (explode("\n", $event) as $line) {
                $body .= 'data: ' . $line . "\n";
            }
            $body .= "\n";
        }
        return $this->response->raw($body)->withStatus(200)
            ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache, no-transform')->withHeader('X-Accel-Buffering', 'no');
    }
}
