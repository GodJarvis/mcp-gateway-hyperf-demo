<?php

declare(strict_types=1);

namespace App\Repository;

use App\Infrastructure\Database;
use PDO;
use RuntimeException;

final class GatewayRepository
{
    private PDO $pdo;

    public function __construct(Database $database)
    {
        $this->pdo = $database->pdo();
    }

    public function listSystems(): array
    {
        $rows = $this->pdo->query(<<<'SQL'
SELECT s.*, COUNT(t.id) AS tool_count
FROM business_systems s
LEFT JOIN tool_configs t ON t.business_system_id = s.id
GROUP BY s.id
ORDER BY s.id DESC
SQL)->fetchAll();
        return array_map($this->hydrateSystem(...), $rows);
    }

    public function findSystem(int $id): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT s.*, COUNT(t.id) AS tool_count
FROM business_systems s
LEFT JOIN tool_configs t ON t.business_system_id = s.id
WHERE s.id = :id
GROUP BY s.id
SQL);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row ? $this->hydrateSystem($row) : throw new RuntimeException('业务系统不存在', 404);
    }

    public function createSystem(array $data): array
    {
        $statement = $this->pdo->prepare('INSERT INTO business_systems (name, code, description, enabled) VALUES (:name, :code, :description, :enabled)');
        $statement->execute($data);
        return $this->findSystem((int) $this->pdo->lastInsertId());
    }

    public function updateSystem(int $id, array $data): array
    {
        $this->findSystem($id);
        $statement = $this->pdo->prepare('UPDATE business_systems SET name=:name, code=:code, description=:description, enabled=:enabled, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute([...$data, 'id' => $id]);
        return $this->findSystem($id);
    }

    public function setSystemStatus(int $id, bool $enabled): array
    {
        $system = $this->findSystem($id);
        return $this->updateSystem($id, [
            'name' => $system['name'], 'code' => $system['code'], 'description' => $system['description'], 'enabled' => (int) $enabled,
        ]);
    }

    public function deleteSystem(int $id): void
    {
        $system = $this->findSystem($id);
        if ((int) $system['tool_count'] > 0) {
            throw new RuntimeException('请先删除该业务系统下的工具', 409);
        }
        $statement = $this->pdo->prepare('DELETE FROM business_systems WHERE id=:id');
        $statement->execute(['id' => $id]);
    }

    public function listTools(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE t.enabled=1 AND s.enabled=1' : '';
        $rows = $this->pdo->query(<<<SQL
SELECT t.*, s.name AS business_system_name, s.enabled AS business_system_enabled
FROM tool_configs t
JOIN business_systems s ON s.id=t.business_system_id
{$where}
ORDER BY t.name
SQL)->fetchAll();
        return array_map($this->hydrateTool(...), $rows);
    }

    public function findTool(int $id): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT t.*, s.name AS business_system_name, s.enabled AS business_system_enabled
FROM tool_configs t JOIN business_systems s ON s.id=t.business_system_id
WHERE t.id=:id
SQL);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row ? $this->hydrateTool($row) : throw new RuntimeException('工具不存在', 404);
    }

    public function findActiveToolByName(string $name): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT t.*, s.name AS business_system_name, s.enabled AS business_system_enabled
FROM tool_configs t JOIN business_systems s ON s.id=t.business_system_id
WHERE t.name=:name AND t.enabled=1 AND s.enabled=1
SQL);
        $statement->execute(['name' => $name]);
        $row = $statement->fetch();
        return $row ? $this->hydrateTool($row) : throw new RuntimeException('工具不存在或已停用：' . $name, 404);
    }

    public function createTool(array $data): array
    {
        $this->findSystem((int) $data['business_system_id']);
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO tool_configs (
 business_system_id,name,description,endpoint_url,http_method,timeout_seconds,input_schema,output_schema,
 static_headers,auth_type,auth_header_name,auth_username,auth_secret_ciphertext,enabled
) VALUES (
 :business_system_id,:name,:description,:endpoint_url,:http_method,:timeout_seconds,:input_schema,:output_schema,
 :static_headers,:auth_type,:auth_header_name,:auth_username,:auth_secret_ciphertext,:enabled
)
SQL);
        $statement->execute($this->serializeTool($data));
        return $this->findTool((int) $this->pdo->lastInsertId());
    }

    public function updateTool(int $id, array $data): array
    {
        $this->findTool($id);
        $this->findSystem((int) $data['business_system_id']);
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE tool_configs SET
 business_system_id=:business_system_id,name=:name,description=:description,endpoint_url=:endpoint_url,
 http_method=:http_method,timeout_seconds=:timeout_seconds,input_schema=:input_schema,output_schema=:output_schema,
 static_headers=:static_headers,auth_type=:auth_type,auth_header_name=:auth_header_name,auth_username=:auth_username,
 auth_secret_ciphertext=:auth_secret_ciphertext,enabled=:enabled,updated_at=CURRENT_TIMESTAMP
WHERE id=:id
SQL);
        $statement->execute([...$this->serializeTool($data), 'id' => $id]);
        return $this->findTool($id);
    }

    public function setToolStatus(int $id, bool $enabled): array
    {
        $statement = $this->pdo->prepare('UPDATE tool_configs SET enabled=:enabled, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute(['enabled' => (int) $enabled, 'id' => $id]);
        if ($statement->rowCount() === 0) {
            $this->findTool($id);
        }
        return $this->findTool($id);
    }

    public function deleteTool(int $id): void
    {
        $this->findTool($id);
        $statement = $this->pdo->prepare('DELETE FROM tool_configs WHERE id=:id');
        $statement->execute(['id' => $id]);
    }

    public function addAudit(array $data): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO audit_logs (tool_id,tool_name,source,success,http_status,duration_ms,argument_keys,error_message)
VALUES (:tool_id,:tool_name,:source,:success,:http_status,:duration_ms,:argument_keys,:error_message)
SQL);
        $data['argument_keys'] = json_encode($data['argument_keys'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $data['success'] = (int) $data['success'];
        $statement->execute($data);
    }

    public function listAuditLogs(int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM audit_logs ORDER BY id DESC LIMIT :limit');
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['tool_id'] = $row['tool_id'] === null ? null : (int) $row['tool_id'];
            $row['success'] = (bool) $row['success'];
            $row['http_status'] = $row['http_status'] === null ? null : (int) $row['http_status'];
            $row['duration_ms'] = (int) $row['duration_ms'];
            $row['argument_keys'] = json_decode($row['argument_keys'], true, 512, JSON_THROW_ON_ERROR);
            return $row;
        }, $statement->fetchAll());
    }

    private function hydrateTool(array $row): array
    {
        foreach (['input_schema', 'output_schema', 'static_headers'] as $field) {
            $row[$field] = $row[$field] === null ? null : json_decode($row[$field], true, 512, JSON_THROW_ON_ERROR);
        }
        foreach (['id', 'business_system_id', 'timeout_seconds'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['enabled'] = (bool) $row['enabled'];
        $row['business_system_enabled'] = (bool) $row['business_system_enabled'];
        $row['auth_secret_configured'] = $row['auth_secret_ciphertext'] !== null;
        return $row;
    }

    private function hydrateSystem(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['enabled'] = (bool) $row['enabled'];
        $row['tool_count'] = (int) $row['tool_count'];
        return $row;
    }

    private function serializeTool(array $data): array
    {
        foreach (['input_schema', 'output_schema', 'static_headers'] as $field) {
            $data[$field] = $data[$field] === null ? null : json_encode($data[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $data['enabled'] = (int) $data['enabled'];
        return $data;
    }
}
