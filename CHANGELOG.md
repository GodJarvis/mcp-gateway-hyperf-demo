# Changelog

## Unreleased — official MCP SDK

- 使用 `mcp/sdk ^0.9` 替换手写 JSON-RPC、版本协商和 HTTP/SSE 编码。
- 每个请求动态注册数据库工具，工具修改与启停实时生效。
- 增加 SDK 旧协议文件会话、DELETE 会话结束、OPTIONS 和 Host/Origin 校验。
- 增加 Swoole ResponseEmitter 适配 SDK CallbackStream，实时发送 SSE progress 与结果。
- 保留管理台、工具执行器、凭证加密和审计。
- 增加真实 HTTP 集成测试，覆盖现代/旧协议、SSE、会话与动态工具。

## v1.0

Git 标签 `v1.0` 固定于 `d27247c`，保留 SDK 重构前的手写协议实现。

- Hyperf 3.2 / PHP 8.5 后端与 React 管理台。
- 动态业务系统、工具配置、HTTP 转发、密钥加密和调用审计。
- 手工实现 MCP tools/list、tools/call、初始化/发现与 SSE。
- 默认监听端口 9501。
