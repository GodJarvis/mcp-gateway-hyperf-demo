<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
echo json_encode([
    'ok' => true,
    'received' => $body,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'api_key' => $_SERVER['HTTP_X_API_KEY'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
