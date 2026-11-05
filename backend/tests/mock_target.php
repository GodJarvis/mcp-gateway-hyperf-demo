<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
usleep(min(2000, max(0, (int) ($_SERVER['HTTP_X_TEST_DELAY_MS'] ?? $_GET['delay_ms'] ?? 0))) * 1000);
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
echo json_encode([
    'status' => 'ok',
    'ok' => true,
    'received' => $body,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'api_key' => $_SERVER['HTTP_X_API_KEY'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
