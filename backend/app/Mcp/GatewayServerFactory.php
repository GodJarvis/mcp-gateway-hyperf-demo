<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Repository\GatewayRepository;
use App\Service\ToolExecutor;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;

final class GatewayServerFactory
{
    public function __construct(private readonly GatewayRepository $repository, private readonly ToolExecutor $executor)
    {
    }

    public function create(): Server
    {
        // Request-local registry and transport state for Hyperf coroutine workers.
        $builder = Server::builder()
            ->setServerInfo('enterprise-mcp-gateway-hyperf', '1.1.0')
            ->setInstructions('通过 Gateway 动态调用已启用的业务 HTTP 工具。')
            ->setSession(new FileSessionStore(BASE_PATH . '/runtime/mcp-sessions'))
            ->setLazyLoading(false);
        foreach ($this->repository->listTools(true) as $tool) {
            $builder->add(new Tool(
                name: $tool['name'], title: null, inputSchema: $tool['input_schema'],
                description: $tool['description'], annotations: null, outputSchema: $tool['output_schema'],
            ), new DynamicToolHandler($tool['name'], $this->repository, $this->executor));
        }
        return $builder->build();
    }
}
