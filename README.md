# MCP Gateway Hyperf Demo

一个可运行的企业级动态 MCP Gateway 示例：后端使用 **Hyperf 3.2 + PHP 8.5 + SQLite**，管理台使用 **React 19 + TypeScript + Ant Design**。管理员在页面配置业务 HTTP 接口后，MCP 客户端即可通过统一的 Streamable HTTP `/mcp` 端点发现并调用这些工具。

当前主分支使用官方 **`mcp/sdk ^0.9`**。手写协议旧版保留在 Git 标签 **`v1.0`**（提交 `d27247c`），可用 `git switch --detach v1.0` 查看。

## 能力

- 业务系统与 HTTP 工具 CRUD、启停和试调用
- 动态 `tools/list` / `tools/call`，无需重启即可读取数据库中的启用工具
- SDK Streamable HTTP 单端点，支持 JSON 响应和请求级 SSE 进度/结果流
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
│   ├── app/Mcp/             SDK Server 工厂、动态 Handler、Swoole 流适配
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
- 后端健康检查：<http://localhost:9501/api/health>
- MCP Endpoint：<http://localhost:9501/mcp>

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
  "url": "http://localhost:9501/mcp",
  "headers": {
    "Authorization": "Bearer dev-mcp-key"
  }
}
```

协议版本、JSON-RPC 校验和传输由官方 SDK 处理。客户端应同时接受 `application/json` 与 `text/event-stream`：普通请求允许返回 JSON，工具调用传入 `_meta.progressToken` 时，SDK 产生 SSE 进度与最终结果流。`SdkResponseEmitter` 将 SDK CallbackStream 的输出实时写入 Swoole chunked 响应。

旧协议客户端先 `initialize`，随后携带 SDK 返回的 `Mcp-Session-Id`；会话保存到 `backend/runtime/mcp-sessions`，支持 `DELETE /mcp` 结束会话。现代 `2026-07-28` 客户端使用每个请求的 `_meta`，无需初始化和会话。路由接收 POST、GET、DELETE、OPTIONS；GET 的具体行为由 SDK 决定（当前无独立 GET SSE 通道）。

每个请求通过 `GatewayServerFactory` 从数据库注册当前启用工具，不共享可变 SDK Server/Transport；管理台新增、修改、启停工具后，下一次请求立即生效。未配置列表变更推送订阅，客户端需要重新调用 `tools/list`。

`MCP_ALLOWED_HOSTS` 配置允许的 Host（不带端口，逗号分隔）；`MCP_ALLOWED_ORIGINS` 配置允许的浏览器来源。通过非 localhost 地址访问时需同步配置 Host 白名单。

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

# 后端启动后，另开终端运行真实 HTTP/SDK 集成测试
TEST_BASE_URL=http://127.0.0.1:9501 php tests/http_test.php

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
- SDK `0.x` 尚未发布 1.0，升级时需要重新验证协议兼容性。
- 旧协议的文件会话适合单机；多节点需要共享 Session Store 和相应并发锁。当前网关未提供客户端交互式请求或长生命周期订阅。
