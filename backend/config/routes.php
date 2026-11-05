<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\McpController;
use Hyperf\HttpServer\Router\Router;

Router::get('/api/health', [AdminController::class, 'health']);
Router::get('/api/systems', [AdminController::class, 'listSystems']);
Router::post('/api/systems', [AdminController::class, 'createSystem']);
Router::put('/api/systems/{id}', [AdminController::class, 'updateSystem']);
Router::patch('/api/systems/{id}/status', [AdminController::class, 'setSystemStatus']);
Router::delete('/api/systems/{id}', [AdminController::class, 'deleteSystem']);
Router::get('/api/tools', [AdminController::class, 'listTools']);
Router::post('/api/tools', [AdminController::class, 'createTool']);
Router::put('/api/tools/{id}', [AdminController::class, 'updateTool']);
Router::patch('/api/tools/{id}/status', [AdminController::class, 'setToolStatus']);
Router::delete('/api/tools/{id}', [AdminController::class, 'deleteTool']);
Router::post('/api/tools/{id}/test', [AdminController::class, 'testTool']);
Router::get('/api/audit-logs', [AdminController::class, 'listAuditLogs']);
Router::post('/mcp', [McpController::class, 'handle']);
