# MCP Gateway Hyperf Demo

一个可运行的企业级动态 MCP Gateway 示例：后端使用 **Hyperf 3.2 + PHP 8.5 + SQLite**，管理台使用 **React 19 + TypeScript + Ant Design**。管理员在页面配置业务 HTTP 接口后，MCP 客户端即可通过统一的 Streamable HTTP `/mcp` 端点发现并调用这些工具。

## 能力

- 业务系统与 HTTP 工具 CRUD、启停和试调用
- 动态 `tools/list` / `tools/call`，无需重启即可读取数据库中的启用工具
- Streamable HTTP 单端点，JSON-RPC 结果以 SSE `event: message` 帧返回
- 入参与出参 JSON Schema 校验
- GET/DELETE 转 Query，其余方法发送 JSON Body
- 每个工具独立配置静态 Header、Basic、Bearer 或 API Key
- 认证密钥使用 AES-256-GCM 加密，管理 API 永不回传密文或明文
- Admin Token 与 MCP Token 分离
- 调用审计只记录参数名、状态和耗时，不记录参数值或认证密钥
- 禁止自动重定向，并支持生产环境禁用私网目标

## 目录

```text
.
├── backend/                 Hyperf 3.2 / PHP 8.5
│   ├── app/Controller/      管理 API 与 MCP HTTP 入口
│   ├── app/Mcp/             MCP JSON-RPC 协议处理
│   ├── app/Service/         动态 HTTP 工具执行器
│   ├── app/Repository/      SQLite 数据访问与审计
│   └── tests/               核心测试与本地回显服务
├── frontend/                React 管理台
└── docker-compose.yml
```

## Docker Compose 启动

```bash
cp .env.example .env
# 上线前务必替换三个密钥
docker compose up --build
```

- 管理台：<http://localhost:5173>
- 后端健康检查：<http://localhost:8000/api/health>
- MCP Endpoint：<http://localhost:8000/mcp>

开发默认 Admin Token 为 `dev-admin-key`，MCP Token 为 `dev-mcp-key`，仅适合本地演示。

## 在指定 PHP 8.5 容器中开发

当前目录映射到容器 `/var/project/vibe-demo` 时：

```bash
docker exec -it 7635b11631d9 sh
cd /var/project/vibe-demo/mcp-gateway-hyperf-demo/backend
composer install
cp .env.example .env
php bin/hyperf.php start
```

另一个终端启动前端：

```bash
cd frontend
npm ci
npm run dev
```

## MCP 客户端配置

```json
{
  "type": "streamable-http",
  "url": "http://localhost:8000/mcp",
  "headers": {
    "Authorization": "Bearer dev-mcp-key"
  }
}
```

兼容协议初始化和现代 `2026-07-28` 请求元数据。HTTP 请求必须同时接受 `application/json` 与 `text/event-stream`；工具调用结果以 SSE 帧返回。现代协议请求还需提供匹配的 `MCP-Protocol-Version`、`Mcp-Method`，调用工具时提供 `Mcp-Name`。

## 管理 API

除健康检查外，管理接口均要求：

```http
Authorization: Bearer dev-admin-key
```

| 方法 | 路径 | 用途 |
|---|---|---|
| GET | `/api/health` | 健康检查 |
| GET/POST | `/api/systems` | 系统列表/创建 |
| PUT/DELETE | `/api/systems/{id}` | 系统更新/删除 |
| PATCH | `/api/systems/{id}/status` | 系统启停 |
| GET/POST | `/api/tools` | 工具列表/创建 |
| PUT/DELETE | `/api/tools/{id}` | 工具更新/删除 |
| PATCH | `/api/tools/{id}/status` | 工具启停 |
| POST | `/api/tools/{id}/test` | 控制台试调用 |
| GET | `/api/audit-logs` | 审计日志 |

## 验证

```bash
cd backend
composer validate --strict
composer audit --locked
composer test

cd ../frontend
npm ci
npm run typecheck
npm run build
```

## 生产边界

- 将 `ADMIN_API_KEY`、`MCP_API_KEY`、`SECRET_ENCRYPTION_KEY` 换成长随机值并交由密钥系统管理。
- 设置 `ALLOW_PRIVATE_TARGETS=false`，并在出站代理或防火墙实现域名/IP 白名单；应用层 DNS 校验不能替代网络层出口控制。
- 通过 HTTPS 反向代理公开服务，限制请求体大小、并发数和速率。
- SQLite 适合示例与单实例；多实例部署应替换为 MySQL/PostgreSQL 并增加迁移与并发控制。
- 认证密钥变更后不要直接更换 `SECRET_ENCRYPTION_KEY`；生产实现应支持密钥版本和轮换。
