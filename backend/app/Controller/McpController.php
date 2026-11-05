<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mcp\GatewayServerFactory;
use GuzzleHttp\Psr7\HttpFactory;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponse;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class McpController
{
    public function __construct(
        private readonly GatewayServerFactory $factory,
        private readonly HyperfResponse $response,
        private readonly ConfigInterface $config,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'OPTIONS' && ! hash_equals(
            'Bearer ' . (string) $this->config->get('gateway.mcp_api_key'), $request->getHeaderLine('Authorization'),
        )) {
            return $this->response->json(['detail' => 'MCP 客户端认证失败'])->withStatus(401)->withHeader('WWW-Authenticate', 'Bearer');
        }
        $psr17 = new HttpFactory();
        $origins = $this->config->get('gateway.mcp_allowed_origins', []);
        $transport = new StreamableHttpTransport($request, $psr17, $psr17, middleware: [
            new CorsMiddleware(allowedOrigins: $origins),
            new DnsRebindingProtectionMiddleware(
                allowedHosts: $this->config->get('gateway.mcp_allowed_hosts'), allowedOrigins: $origins,
                responseFactory: $psr17, streamFactory: $psr17,
            ),
        ]);
        $result = $this->factory->create()->run($transport);
        return $result->withHeader('Cache-Control', 'no-store')->withHeader('X-Accel-Buffering', 'no');
    }
}
