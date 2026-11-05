<?php

declare(strict_types=1);

namespace App\Mcp;

use Hyperf\HttpServer\ResponseEmitter;
use Mcp\Server\Transport\CallbackStream;
use Psr\Http\Message\ResponseInterface;

/** Bridge SDK SAPI output to Swoole chunked responses without re-encoding MCP. */
final class SdkResponseEmitter extends ResponseEmitter
{
    public function emit(ResponseInterface $response, mixed $connection, bool $withContent = true): void
    {
        if (! $withContent || ! $response->getBody() instanceof CallbackStream) {
            parent::emit($response, $connection, $withContent);
            return;
        }
        $this->buildSwooleResponse($connection, $response);
        ob_start(static function (string $chunk) use ($connection): string {
            if ($chunk !== '' && ! $connection->write($chunk)) {
                throw new \RuntimeException('MCP SSE connection closed');
            }
            return '';
        }, 1);
        try {
            $response->getBody()->getContents();
        } catch (\Throwable $exception) {
            $this->logger?->error('MCP SSE emission failed: ' . $exception->getMessage());
        } finally {
            ob_end_clean();
            $connection->end();
        }
    }
}
