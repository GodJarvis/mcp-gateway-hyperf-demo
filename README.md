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

## 数据库新增 Tool 为什么无需重启

假设客户端第一次调用 `tools/list` 时，数据库只有 `get_order`。管理员随后在管理台新增 `calculate_discount` 并保存。客户端再次调用 `tools/list`，服务端就会读取到新记录，把它注册为 SDK Tool；客户端再发 `tools/call` 时，网关根据配置调用折扣接口。

这里的“动态加载”是**每个 MCP 请求按当前数据库配置重新注册工具**。项目没有数据库变更监听器，也没有生成新的 PHP 类文件。

### 1. 管理台保存的是工具配置

管理台调用 `POST /api/tools`，经过 [AdminController::createTool()](backend/app/Controller/AdminController.php)、[PayloadValidator::tool()](backend/app/Validation/PayloadValidator.php) 和 [GatewayRepository::createTool()](backend/app/Repository/GatewayRepository.php)，最终写入 `tool_configs`。

一条记录分为两部分：

| 配置 | 用于什么 | 是否提供给 MCP 客户端 |
|---|---|---|
| `name`、`description`、`input_schema`、`output_schema` | 描述工具用途和调用契约 | 是，转换为 MCP Tool 定义 |
| `endpoint_url`、`http_method`、`timeout_seconds` | 决定如何调用业务 HTTP 接口 | 否 |
| `static_headers`、认证类型、用户名、密钥密文 | 构造业务请求的认证 Header | 否 |
| 工具 `enabled`、所属系统 `enabled` | 决定工具是否对 MCP 开放 | 不作为工具配置字段暴露，用于筛选 |

保存接口负责校验配置和加密密钥，此时不会执行目标业务接口，也不会修改某个常驻的 SDK Registry。

### 2. 每次请求重新查询、构建、注册

请求入口 [McpController::handle()](backend/app/Controller/McpController.php) 中的关键调用是：

```php
$result = $this->factory->create()->run($transport);
```

每执行一次 `handle()`，就执行一次 [GatewayServerFactory::create()](backend/app/Mcp/GatewayServerFactory.php)。即使 Hyperf 容器复用了 Controller 或 Factory 对象，`create()` 内部仍然创建新的 Builder、Registry 和 Server；工厂没有缓存上一次创建的 Server。

工厂先调用 `listTools(true)`，SQL 的筛选条件为：

```sql
SELECT t.*, s.name AS business_system_name, s.enabled AS business_system_enabled
FROM tool_configs t
JOIN business_systems s ON s.id = t.business_system_id
WHERE t.enabled = 1 AND s.enabled = 1
ORDER BY t.name;
```

只有工具和业务系统同时启用，记录才会进入 SDK Registry。`hydrateTool()` 再把数据库中的 JSON 字符串解码为 PHP 数组。

接着为每条记录创建一份定义和一个执行处理器：

```php
$builder->add(
    new Tool(
        name: $tool['name'],
        title: null,
        inputSchema: $tool['input_schema'],
        description: $tool['description'],
        annotations: null,
        outputSchema: $tool['output_schema'],
    ),
    new DynamicToolHandler($tool['name'], $this->repository, $this->executor),
);
```

所有数据库工具都复用同一个 `DynamicToolHandler` 类，只是处理器实例中的 `$name` 不同。因此新增工具只需增加配置，不必新增 PHP 方法或部署代码。数据库中的 `name` 是注册、发现和执行查询之间的关联键。

### 3. 注册时读取定义，执行时再次读取配置

`tools/list` 从这次请求的 Registry 读取工具定义；`tools/call` 从这次请求的 Registry 按 `params.name` 查找处理器。

进入 [DynamicToolHandler::execute()](backend/app/Mcp/DynamicToolHandler.php) 后，会再次执行：

```php
$tool = $this->repository->findActiveToolByName($this->name);
```

这次查询重新检查启用状态，并取得端点、认证、Header 等实际执行配置。处理器没有把注册时的一整行数据库记录长期保存在内存中。

注意这是两个时间点的查询，并非一个原子快照。如果工具在注册后、执行前被停用或删除，第二次查询会失败；如果配置在两次查询间被更新，SDK 注册时的 Schema 与执行器读取的配置可能不同。当前方案保证后续请求重新加载配置，不承诺已开始的请求自动切换配置，也不提供“停用即取消正在执行的调用”。

### 4. 服务端刷新与客户端刷新是两件事

| 操作 | 下一次服务端 MCP 请求的行为 | 客户端需要做什么 |
|---|---|---|
| 新增启用工具 | 新 Registry 包含该工具 | 重新执行 `tools/list` |
| 修改描述或 Schema | 新 Registry 使用新定义 | 刷新工具列表，避免继续使用旧契约 |
| 修改端点或认证 | 后续执行读取新配置 | 工具名称未变时仍通过相同名称调用 |
| 停用工具或所属系统 | 新 Registry 不再包含工具 | 刷新列表；旧名称调用会被拒绝 |
| 删除工具 | 不再注册该工具 | 移除客户端缓存的工具定义 |

本项目没有配置 SDK Notification Bus 或工具列表变更订阅，不会主动向已经连接的客户端推送 `notifications/tools/list_changed`。所以“无需重启”表示服务端下一次请求会读取新配置，并不表示 Agent 已缓存的工具列表自动更新。

旧协议客户端还可能缓存 `initialize` 返回的 capabilities。如果它在系统完全没有工具时初始化，之后新增第一个工具，应重新初始化/连接并发现能力；已经具备 tools 能力的客户端也需要重新拉取列表。现代客户端同样应按自己的发现缓存策略刷新。

## mcp/sdk 在项目中如何工作

下面的内部类名和行为以 `composer.lock` 锁定的 **`mcp/sdk v0.9.0`** 为准。SDK 负责 MCP 协议，本项目负责数据库配置和业务执行；安装 SDK 后并不会自动获得管理台、数据库工具或业务鉴权。

### 1. Builder 将配置转换为 Registry 与协议处理器

项目采用 `Builder::add(Tool, ToolHandlerInterface)` 显式注册，因为工具名称、描述和 Schema 都在运行时从数据库取得。没有调用 `setDiscovery()` 扫描 Attribute，也不依赖反射一个固定 PHP 方法来生成工具参数。

SDK 的构建链是：

```text
GatewayServerFactory::create()
  → Server::builder()
  → Builder::add(Tool 定义, DynamicToolHandler)
  → Builder::build()
      → ExplicitElementLoader
      → Registry::registerTool(定义, 包装后的执行闭包)
      → ListToolsHandler / CallToolHandler 等协议处理器
      → 旧协议 Protocol + 现代 StatelessProtocol
      → Server
```

`add()` 先保存定义与 Handler 的配对，并检查元素类型是否匹配。`build()` 通过 Loader 把配对转换成 Registry 中的工具引用，工具引用把公开定义与执行逻辑关联起来。Registry 是请求内的内存注册表，不是数据库表。

`setLazyLoading(false)` 让 Loader 在构建时填充 Registry，并据此检测 capabilities。它本身不会监听数据库，也不会触发定时刷新；刷新来自每个请求都重新执行 `create()`。

`ExplicitElementLoader` 包装 Handler 时还会从 SDK 内部参数取出 Session 和 Request，创建 `ClientGateway`，移除 `_session`、`_request`，再调用：

```php
$handler->execute($arguments, $gateway);
```

因此动态工具收到的是业务参数映射，不需要给每一种工具声明不同的方法参数，也不会把 SDK 内部 Session/Request 传给业务接口。

### 2. Transport 把 HTTP 请求接到协议层

[McpController](backend/app/Controller/McpController.php) 先检查网关 MCP Bearer Token，再创建 `StreamableHttpTransport`。Hyperf 提供 PSR-7 Request；`GuzzleHttp\Psr7\HttpFactory` 提供 PSR-17 Response/Stream 工厂，SDK 返回 PSR-7 Response。

```text
POST /mcp
  → 网关 Bearer Token 检查
  → SDK CORS、Host/Origin 校验
  → InboundClassifier 判断协议时代
  → 对应协议解析请求、校验消息与元数据
  → 按请求类型选择 SDK Handler
  → 执行业务 Handler
  → SDK 序列化 JSON 或 SSE Response
  → Hyperf ResponseEmitter 发送响应
```

`Server::run()` 为 Transport 连接协议处理器并调用 `listen()`。Transport 负责 HTTP 边界和传输，协议处理器负责消息的语义；普通 PHP HTTP 框架不需要自己实现这些 JSON-RPC 分发规则。

本项目保留两层认证：客户端访问 `/mcp` 的 Bearer Token 由 Controller 校验；网关访问业务接口的 Basic/Bearer/API Key 由 `ToolExecutor` 注入。SDK 的 Host/Origin 防护不能代替业务目标地址的出站访问控制。

### 3. tools/list 返回 Registry 中的定义

SDK 将请求解析为 `ListToolsRequest`，由 `ListToolsHandler` 调用 Registry 的 `getTools()`，生成 `ListToolsResult`。序列化后的 MCP 字段使用 `inputSchema`、`outputSchema`，与管理 API 中的 `input_schema`、`output_schema` 命名不同。

`tools/list` 不会执行工具，也不会向客户端发送执行器中的认证密钥。返回的是 `Tool` 对象中的公开元数据。

SDK 支持游标分页；本项目使用 Builder 默认分页大小 50（Builder 会将该值传给 ListToolsHandler，覆盖 Handler 构造参数自身的默认值 20）。当响应带有 `nextCursor` 时，客户端要用该游标继续请求，不能把第一页当成全部工具。每页请求都会重建 Registry，因此分页期间发生增删时应重新拉取完整列表，当前没有跨页配置快照。

### 4. tools/call 校验参数后调用 Handler

完整执行链为：

```text
CallToolRequest
  → CallToolHandler
  → Registry::getTool(params.name)
  → 校验 arguments 与注册的 inputSchema
  → ReferenceHandler 执行工具引用中的闭包
  → DynamicToolHandler::execute(arguments, ClientGateway)
      → findActiveToolByName() 再读数据库
      → progress(0, 1)
      → ToolExecutor::execute()
          → 入参 Schema 校验、目标地址检查
          → 解密认证密钥、构造 Header
          → GET/DELETE 使用 Query，其他方法使用 JSON Body
          → 调用业务接口、解析结果、校验出参 Schema
      → 写入 MCP 审计日志
      → progress(1, 1)
      → 返回 CallToolResult
  → SDK 校验实际 structuredContent 与 outputSchema
  → 编码并发送最终结果
```

入参校验存在两层：SDK 在调用 Handler 前校验，执行器在发出 HTTP 请求前再次校验。后者也服务于管理台 `/api/tools/{id}/test`，控制台试调用直接走执行器，不经过 MCP 协议层。

当前 Handler 显式返回 `CallToolResult`：`content` 是给模型读取的文本内容；业务结果为非列表关联数组时，再放入 `structuredContent` 供程序读取。不是所有业务返回值都一定包含 `structuredContent`。SDK 还支持自动包装 PHP 返回值，本项目采用显式包装以保持网关的返回约定。

失败时要区分三层：

| 情况 | 当前行为 | 是否进入业务执行器 |
|---|---|---|
| Tool 名称未注册 | SDK 返回 JSON-RPC `-32602` 参数错误 | 否 |
| 入参不符合 Registry 中的 Schema | SDK 返回 JSON-RPC `-32602` 参数错误 | 否 |
| 目标超时、HTTP 4xx/5xx、执行器出参校验失败 | 返回 `CallToolResult`，`isError: true` | 是 |
| SDK 最终 structuredContent 校验失败 | SDK 转为 `isError: true` 的工具结果 | 已执行 |
| 注册后、执行前工具被删除/停用，查询抛出异常 | SDK 调用处理器捕获后返回内部错误 | 未发起目标 HTTP 请求 |

HTTP 200 只表示 HTTP 请求被处理；JSON-RPC `error` 或 `result.isError` 才描述协议/工具失败。SDK 在调用 Handler 前拒绝的请求，不会走到当前 Handler 的审计代码；当前审计不是覆盖所有入站 MCP 请求的访问日志。

### 5. 旧协议 Session 与现代无状态请求

一个 `Server` 内包含两套协议调度器，共享本次请求的 Registry 和业务处理器。`InboundClassifier` 根据请求判断走哪套协议：

| 项目 | 旧协议，例如 `2025-11-25` | 现代 `2026-07-28` |
|---|---|---|
| 初始发现 | `initialize` 协商版本、能力 | `server/discover` 与请求元数据 |
| 请求携带信息 | 初始化后携带 `Mcp-Session-Id` | 每个请求携带协议版本和客户端能力 `_meta` |
| HTTP 中的状态 | 跨请求恢复 Session | 不依赖旧协议 Session ID |
| 结束 | `DELETE /mcp` | 无旧协议 Session 可删除 |
| 工具配置来源 | 本次请求的新 Registry | 本次请求的新 Registry |

现代请求的协议版本声明以 `params._meta` 为依据，HTTP Header 用于校验，不能只设置一个现代版本 Header 就把旧式请求体变成现代请求。HTTP 下还会校验 `Mcp-Method`，调用工具时校验 `Mcp-Name` 等匹配关系。

每次重建 Server 不会让旧协议 Session 消失，因为 [GatewayServerFactory](backend/app/Mcp/GatewayServerFactory.php) 每次使用同一目录的 `FileSessionStore`。Session 保存跨请求的协议状态；新 Registry 保存当前工具定义，两者生命周期不同。PHP 对象可以重建，Session 数据仍可从文件恢复。

### 6. SSE 为什么能够在业务接口完成前发送

`ClientGateway` 是 SDK 给 Handler 的通信入口。本项目调用 `progress()` 发送开始/结束通知。SDK 会从原请求 `_meta.progressToken` 取关联标识；没有 progress token 时，这两个 `progress()` 调用直接返回，不会凭空发送进度通知。

现代协议下，SDK 使用 Fiber 暂停/恢复业务 Handler：执行到首次通知时，把通知交给外层 Generator；客户端接受 SSE 时，Transport 先返回带 `CallbackStream` 的 Response。随后读取该 Stream，才继续生成通知和最终结果。Fiber 本身不创建后台线程，目标 HTTP 请求仍由现有 Hyperf/Guzzle 执行。

```text
客户端请求 tools/call，携带 progressToken
  → SDK 启动 Handler
  → progress(0, 1)：Fiber 暂停，SDK 得到首个通知
  → 返回 SSE Response，Emitter 先发送 Header
  → 首个进度帧写入连接
  → 恢复 Handler，执行目标 HTTP 请求
  → progress(1, 1)：发送完成通知
  → CallToolResult：发送带原请求 id 的最终结果
  → 结束响应
```

SDK `CallbackStream` 的原始输出方式是 `echo` / `flush()`。Hyperf 使用自己的 Swoole 响应对象，所以 [SdkResponseEmitter](backend/app/Mcp/SdkResponseEmitter.php) 通过输出缓冲回调把 SDK 输出逐块交给 `$connection->write()`，最后执行 `end()`。适配器不重新解析或拼装 MCP 消息；普通 JSON Response 继续交给 Hyperf 默认发送逻辑。

SSE 的 `data:` 行和空行是帧边界，`event: message` 可以省略，SDK 在现代协议流中采用省略形式。客户端应按 SSE 格式解析后再读取 JSON-RPC，不能按 TCP chunk 或 HTTP chunk 直接当成一条完整消息。

只设置 `Accept: text/event-stream` 并不会强制所有请求都成为流。当前无通知的普通结果可以返回 `application/json`；带 progress token 的正常工具执行会产生进度与最终结果流。首个进度发出后，后续错误通过流中的最终错误帧表达，不能再修改已经发送的 HTTP 状态。

## 一个可复现的动态注册例子

先启动后端。这个例子把网关自身的健康检查注册为 `gateway_health`，用于观察配置变成 MCP Tool 的全过程；它不依赖外部业务接口。需要演示配置 `ALLOW_PRIVATE_TARGETS=true`，Shell 中安装 `curl`、`jq`。

```bash
# 使用当前服务的实际 Token；下面是开发默认值。
gateway_admin_key=dev-admin-key
gateway_mcp_key=dev-mcp-key
gateway_base=http://127.0.0.1:9501

# 创建一个启用的业务系统；重复执行时应更换唯一编码/名称。
gateway_system_id=$(curl -fsS "$gateway_base/api/systems" \
  -H "Authorization: Bearer $gateway_admin_key" \
  -H 'Content-Type: application/json' \
  --data '{"name":"SDK 动态注册演示","code":"sdk_dynamic_demo","enabled":true}' | jq -r '.id')

# 新增数据库工具；端点中的 127.0.0.1 是后端进程所在网络空间。
gateway_tool_id=$(jq -n --argjson id "$gateway_system_id" --arg url "$gateway_base/api/health" '{
  business_system_id: $id,
  name: "gateway_health",
  description: "检查 MCP Gateway 是否正常运行",
  endpoint_url: $url,
  http_method: "GET",
  input_schema: {type: "object", properties: {}, additionalProperties: false},
  output_schema: {type: "object", required: ["status"]},
  auth_type: "none",
  enabled: true
}' | curl -fsS "$gateway_base/api/tools" \
  -H "Authorization: Bearer $gateway_admin_key" \
  -H 'Content-Type: application/json' --data-binary @- | jq -r '.id')

# 无需重启，立即执行现代 tools/list。
curl -fsS "$gateway_base/mcp" \
  -H "Authorization: Bearer $gateway_mcp_key" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: tools/list' \
  --data '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}'

# 带 progressToken 调用，-N 关闭 curl 自身的输出缓冲。
curl -fsS -N "$gateway_base/mcp" \
  -H "Authorization: Bearer $gateway_mcp_key" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: tools/call' \
  -H 'Mcp-Name: gateway_health' \
  --data '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"gateway_health","arguments":{},"_meta":{"progressToken":"demo-1","io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}'

# 停用后再次执行上面的 tools/list，新 Registry 不再包含该工具。
curl -fsS -X PATCH "$gateway_base/api/tools/$gateway_tool_id/status" \
  -H "Authorization: Bearer $gateway_admin_key" \
  -H 'Content-Type: application/json' --data '{"enabled":false}'
```

`tools/list` 的结果中应包含 `name: gateway_health` 和标准的 `inputSchema.properties: {}`。`tools/call` 的 SSE 流依次包含开始通知、结束通知，以及 `id: 2` 的最终结果；最终 `structuredContent.status` 为 `ok`。停用后再调用该名称，SDK 返回未注册工具的参数错误。

在共享环境运行前请确认示例名称未被占用。例子会保留业务系统和已停用工具，方便从管理台检查；可在管理台先删除工具，再删除业务系统。

## 源码阅读与验证位置

| 要看什么 | 项目或已安装 SDK 文件 |
|---|---|
| 每请求查询和动态注册 | `backend/app/Mcp/GatewayServerFactory.php` |
| Handler 执行和审计 | `backend/app/Mcp/DynamicToolHandler.php` |
| HTTP 入口与 Token、Transport | `backend/app/Controller/McpController.php` |
| SDK 流输出转 Swoole | `backend/app/Mcp/SdkResponseEmitter.php`、`backend/config/autoload/dependencies.php` |
| Builder 构建两套调度器 | `backend/vendor/mcp/sdk/src/Server/Builder.php` |
| 定义与 Handler 转为 Registry 引用 | `backend/vendor/mcp/sdk/src/Capability/Registry/Loader/ExplicitElementLoader.php` |
| 列表分页与调用校验 | `backend/vendor/mcp/sdk/src/Server/Handler/Request/` 下的 `ListToolsHandler.php`、`CallToolHandler.php` |
| 现代请求 Fiber、通知和最终结果 | `backend/vendor/mcp/sdk/src/Server/Stateless/StatelessProtocol.php` |
| Session/现代请求分流 | SDK `src/Server/Transport/StreamableHttpTransport.php`、`src/Server/Wire/InboundClassifier.php` |
| SSE 响应和回调 Stream | SDK `src/Server/Transport/Http/StatelessResponder.php`、`src/Server/Transport/CallbackStream.php` |

`backend/vendor/` 中的类在 `composer install` 后可读，无需修改。官方资料可参考 [SDK v0.9.0 源码](https://github.com/modelcontextprotocol/php-sdk/tree/v0.9.0)、[运行时注册](https://github.com/modelcontextprotocol/php-sdk/blob/v0.9.0/docs/servers/registration.md)、[HTTP Transport](https://github.com/modelcontextprotocol/php-sdk/blob/v0.9.0/docs/run/http.md)。本节描述的是当前锁定版本与项目接入方式。

真实 HTTP 集成测试位于 [backend/tests/http_test.php](backend/tests/http_test.php)，会创建临时系统/工具，检查现代发现、旧协议会话调用、SSE 帧、工具停用后的列表刷新与审计，最后删除自己创建的工具/系统；调用审计记录仍保留。配置 `TEST_TARGET_URL` 指向回显服务时，还会通过目标延迟验证首帧先于最终结果到达。

当前按请求全量查询并构建 Registry，成本随工具数量增长。大规模场景可改为“配置版本号 + 不可变工具定义缓存 + 请求独立的 SDK 运行状态”，但需要明确缓存失效、多实例同步和列表变更通知。本示例尚未实现这些优化。

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
