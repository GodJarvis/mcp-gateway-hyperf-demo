<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\GatewayRepository;
use App\Security\SecretCipher;
use App\Service\ToolExecutor;
use App\Validation\PayloadValidator;
use App\Validation\SchemaValidator;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponse;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class AdminController
{
    public function __construct(
        private readonly GatewayRepository $repository,
        private readonly PayloadValidator $validator,
        private readonly SchemaValidator $schemaValidator,
        private readonly SecretCipher $cipher,
        private readonly ToolExecutor $executor,
        private readonly HyperfResponse $response,
        private readonly ConfigInterface $config,
    ) {
    }

    public function health(): array
    {
        return ['status' => 'ok', 'server' => 'enterprise-mcp-gateway-hyperf', 'php' => PHP_VERSION];
    }

    public function listSystems(ServerRequestInterface $request): ResponseInterface
    {
        return $this->run($request, fn () => $this->repository->listSystems());
    }

    public function createSystem(ServerRequestInterface $request): ResponseInterface
    {
        return $this->run($request, fn () => $this->repository->createSystem($this->validator->system($this->body($request))), 201);
    }

    public function updateSystem(ServerRequestInterface $request, $id): ResponseInterface
    {
        return $this->run($request, fn () => $this->repository->updateSystem((int) $id, $this->validator->system($this->body($request))));
    }

    public function setSystemStatus(ServerRequestInterface $request, $id): ResponseInterface
    {
        return $this->run($request, fn () => $this->repository->setSystemStatus((int) $id, (bool) ($this->body($request)['enabled'] ?? false)));
    }

    public function deleteSystem(ServerRequestInterface $request, $id): ResponseInterface
    {
        return $this->run($request, function () use ($id): null {
            $this->repository->deleteSystem((int) $id);
            return null;
        }, 204);
    }

    public function listTools(ServerRequestInterface $request): ResponseInterface
    {
        return $this->run($request, fn () => array_map($this->publicTool(...), $this->repository->listTools()));
    }

    public function createTool(ServerRequestInterface $request): ResponseInterface
    {
        return $this->run($request, function () use ($request): array {
            $data = $this->prepareTool($this->validator->tool($this->body($request)), null);
            return $this->publicTool($this->repository->createTool($data));
        }, 201);
    }

    public function updateTool(ServerRequestInterface $request, $id): ResponseInterface
    {
        return $this->run($request, function () use ($request, $id): array {
            $existing = $this->repository->findTool((int) $id);
            $data = $this->prepareTool($this->validator->tool($this->body($request)), $existing);
            return $this->publicTool($this->repository->updateTool((int) $id, $data));
        });
    }

    public function setToolStatus(ServerRequestInterface $request, $id): ResponseInterface
    {
        return $this->run($request, fn () => $this->publicTool($this->repository->setToolStatus((int) $id, (bool) ($this->body($request)['enabled'] ?? false))));
    }

    public function deleteTool(ServerRequestInterface $request, $id): ResponseInterface
    {
        return $this->run($request, function () use ($id): null {
            $this->repository->deleteTool((int) $id);
            return null;
        }, 204);
    }

    public function testTool(ServerRequestInterface $request, $id): ResponseInterface
    {
        return $this->run($request, function () use ($request, $id): array {
            $tool = $this->repository->findTool((int) $id);
            $arguments = $this->body($request)['arguments'] ?? [];
            if (! is_array($arguments)) {
                throw new RuntimeException('arguments 必须是 JSON 对象', 422);
            }
            $result = $this->executor->execute($tool, $arguments);
            $this->audit($tool, 'console_test', $arguments, $result);
            return $result;
        });
    }

    public function listAuditLogs(ServerRequestInterface $request): ResponseInterface
    {
        return $this->run($request, function () use ($request): array {
            $limit = max(1, min(500, (int) ($request->getQueryParams()['limit'] ?? 100)));
            return $this->repository->listAuditLogs($limit);
        });
    }

    private function run(ServerRequestInterface $request, callable $callback, int $status = 200): ResponseInterface
    {
        if (! $this->authorized($request)) {
            return $this->json(['detail' => '无效的 Admin Bearer Token'], 401);
        }
        try {
            $value = $callback();
            if ($status === 204) {
                return $this->response->raw('')->withStatus(204)->withoutHeader('Content-Type');
            }
            return $this->json($value, $status);
        } catch (PDOException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique constraint')) {
                return $this->json(['detail' => '名称或编码已存在'], 409);
            }
            return $this->json(['detail' => '数据库操作失败'], 500);
        } catch (RuntimeException $exception) {
            $code = in_array($exception->getCode(), [401, 404, 409, 422], true) ? $exception->getCode() : 500;
            return $this->json(['detail' => $exception->getMessage()], $code);
        } catch (\Throwable) {
            return $this->json(['detail' => '服务器内部错误'], 500);
        }
    }

    private function prepareTool(array $data, ?array $existing): array
    {
        $secret = $data['auth_secret'];
        unset($data['auth_secret']);
        if ($data['auth_type'] === 'none') {
            $data['auth_secret_ciphertext'] = null;
            return $data;
        }
        if ($secret !== null && $secret !== '') {
            $data['auth_secret_ciphertext'] = $this->cipher->encrypt($secret);
            return $data;
        }
        if ($existing === null || $existing['auth_type'] !== $data['auth_type'] || empty($existing['auth_secret_ciphertext'])) {
            throw new RuntimeException('启用或切换认证类型时必须填写认证密钥', 422);
        }
        $data['auth_secret_ciphertext'] = $existing['auth_secret_ciphertext'];
        return $data;
    }

    private function publicTool(array $tool): array
    {
        unset($tool['auth_secret_ciphertext'], $tool['business_system_enabled']);
        $tool['input_schema'] = $this->schemaValidator->present($tool['input_schema']);
        if ($tool['output_schema'] !== null) {
            $tool['output_schema'] = $this->schemaValidator->present($tool['output_schema']);
        }
        if ($tool['static_headers'] === []) {
            $tool['static_headers'] = (object) [];
        }
        return $tool;
    }

    private function audit(array $tool, string $source, array $arguments, array $result): void
    {
        $keys = array_keys($arguments);
        sort($keys);
        $this->repository->addAudit([
            'tool_id' => $tool['id'], 'tool_name' => $tool['name'], 'source' => $source,
            'success' => $result['success'], 'http_status' => $result['http_status'],
            'duration_ms' => $result['duration_ms'], 'argument_keys' => $keys, 'error_message' => $result['error'],
        ]);
    }

    private function body(ServerRequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true);
        if (! is_array($body)) {
            throw new RuntimeException('请求体必须是 JSON 对象', 422);
        }
        return $body;
    }

    private function authorized(ServerRequestInterface $request): bool
    {
        $expected = 'Bearer ' . (string) $this->config->get('gateway.admin_api_key');
        return hash_equals($expected, $request->getHeaderLine('Authorization'));
    }

    private function json(mixed $value, int $status): ResponseInterface
    {
        return $this->response->json($value)->withStatus($status)->withHeader('Cache-Control', 'no-store');
    }
}
