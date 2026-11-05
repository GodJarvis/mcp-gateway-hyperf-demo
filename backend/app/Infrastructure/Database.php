<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Hyperf\Contract\ConfigInterface;
use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(ConfigInterface $config)
    {
        $path = (string) $config->get('gateway.database_path', 'data/gateway.db');
        if (! str_starts_with($path, '/')) {
            $path = BASE_PATH . '/' . $path;
        }
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('无法创建数据库目录：' . $directory);
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS business_systems (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    code TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS tool_configs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    business_system_id INTEGER NOT NULL REFERENCES business_systems(id),
    name TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL,
    endpoint_url TEXT NOT NULL,
    http_method TEXT NOT NULL DEFAULT 'POST',
    timeout_seconds INTEGER NOT NULL DEFAULT 30,
    input_schema TEXT NOT NULL,
    output_schema TEXT,
    static_headers TEXT NOT NULL DEFAULT '{}',
    auth_type TEXT NOT NULL DEFAULT 'none',
    auth_header_name TEXT NOT NULL DEFAULT 'Authorization',
    auth_username TEXT,
    auth_secret_ciphertext TEXT,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tool_id INTEGER,
    tool_name TEXT NOT NULL,
    source TEXT NOT NULL,
    success INTEGER NOT NULL,
    http_status INTEGER,
    duration_ms INTEGER NOT NULL,
    argument_keys TEXT NOT NULL DEFAULT '[]',
    error_message TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
    }
}
