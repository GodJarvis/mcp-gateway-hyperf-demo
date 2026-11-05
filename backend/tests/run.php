<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\GatewayRepository;
use App\Security\SecretCipher;
use App\Validation\PayloadValidator;
use App\Validation\SchemaValidator;
use Hyperf\Config\Config;

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

$databasePath = sys_get_temp_dir() . '/mcp-gateway-test-' . bin2hex(random_bytes(5)) . '.db';
$config = new Config(['gateway' => [
    'database_path' => $databasePath,
    'secret_encryption_key' => 'test-key-with-sufficient-entropy',
]]);

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

try {
    $cipher = new SecretCipher($config);
    $encrypted = $cipher->encrypt('top-secret');
    $assert($encrypted !== 'top-secret', '密钥必须以密文保存');
    $assert($cipher->decrypt($encrypted) === 'top-secret', '密钥必须可正确解密');

    $schema = new SchemaValidator();
    $schema->assertMatches(['price' => 100], [
        'type' => 'object',
        'properties' => ['price' => ['type' => 'number', 'minimum' => 0]],
        'required' => ['price'],
    ]);
    $schema->assertMatches([], ['type' => 'object', 'properties' => [], 'additionalProperties' => false]);
    $assert(json_encode($schema->present(['type' => 'object', 'properties' => []])) === '{"type":"object","properties":{}}', '空 Schema 对象必须保持对象语义');

    $payloadValidator = new PayloadValidator($schema);
    $systemPayload = $payloadValidator->system(['name' => '订单系统', 'code' => 'order_center']);
    $repository = new GatewayRepository(new Database($config));
    $system = $repository->createSystem($systemPayload);
    $assert($system['code'] === 'order_center', '业务系统创建失败');

    $toolPayload = $payloadValidator->tool([
        'business_system_id' => $system['id'],
        'name' => 'quote_discount',
        'description' => '计算折扣',
        'endpoint_url' => 'https://example.com/discount',
        'input_schema' => ['type' => 'object', 'properties' => ['price' => ['type' => 'number']]],
    ]);
    unset($toolPayload['auth_secret']);
    $toolPayload['auth_secret_ciphertext'] = null;
    $tool = $repository->createTool($toolPayload);
    $assert($tool['name'] === 'quote_discount', '工具创建失败');
    $assert(count($repository->listTools(true)) === 1, '启用工具列表不正确');

    echo "PASS SecretCipher\nPASS SchemaValidator\nPASS GatewayRepository\n";
} finally {
    @unlink($databasePath);
    @unlink($databasePath . '-wal');
    @unlink($databasePath . '-shm');
}
