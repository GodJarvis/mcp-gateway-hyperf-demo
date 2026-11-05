<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Repository\GatewayRepository;
use App\Service\ToolExecutor;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

final class DynamicToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private readonly string $name,
        private readonly GatewayRepository $repository,
        private readonly ToolExecutor $executor,
    ) {
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        $tool = $this->repository->findActiveToolByName($this->name);
        $gateway->progress(0, 1, '开始调用业务接口');
        $result = $this->executor->execute($tool, $arguments);
        $keys = array_keys($arguments);
        sort($keys);
        $this->repository->addAudit([
            'tool_id' => $tool['id'], 'tool_name' => $tool['name'], 'source' => 'mcp',
            'success' => $result['success'], 'http_status' => $result['http_status'],
            'duration_ms' => $result['duration_ms'], 'argument_keys' => $keys, 'error_message' => $result['error'],
        ]);
        $gateway->progress(1, 1, '工具调用完成');
        $data = $result['data'];
        $text = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (! $result['success']) {
            return CallToolResult::error([new TextContent(($result['error'] ?? '工具调用失败') . ($data === null ? '' : "\n" . $text))]);
        }
        return new CallToolResult([new TextContent($text)], structuredContent: is_array($data) && ! array_is_list($data) ? $data : null);
    }
}
