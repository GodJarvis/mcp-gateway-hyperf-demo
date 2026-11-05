import { useState } from 'react'
import { CheckCircleOutlined, CopyOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Input, Space, Steps, Typography, message } from 'antd'

export default function GuidePage() {
  const [mcpKey, setMcpKey] = useState(import.meta.env.VITE_MCP_API_KEY ?? 'dev-mcp-key')
  const endpoint = `${window.location.protocol}//${window.location.hostname}:8000/mcp`

  const config = JSON.stringify(
    {
      type: 'streamable-http',
      url: endpoint,
      headers: {
        Authorization: `Bearer ${mcpKey || '<MCP_API_KEY>'}`,
      },
    },
    null,
    2,
  )

  const copy = async (value: string) => {
    await navigator.clipboard.writeText(value)
    message.success('已复制')
  }

  return (
    <div className="page-card guide-page">
      <div className="page-toolbar">
        <div>
          <Typography.Title level={3}>MCP 客户端接入</Typography.Title>
          <Typography.Text type="secondary">本服务使用 Streamable HTTP，所有启用工具通过同一个 `/mcp` 地址提供。</Typography.Text>
        </div>
      </div>

      <Steps
        className="guide-steps"
        items={[
          { title: '创建业务系统', description: '定义工具归属' },
          { title: '配置 HTTP 工具', description: 'Schema、端点与认证' },
          { title: '试调用', description: '验证目标接口' },
          { title: '连接 MCP', description: '交给 Agent 使用' },
        ]}
      />

      <div className="guide-grid">
        <Card title="MCP Endpoint" extra={<CheckCircleOutlined className="success-icon" />}>
          <Space.Compact block>
            <Input value={endpoint} readOnly />
            <Button icon={<CopyOutlined />} onClick={() => void copy(endpoint)}>复制</Button>
          </Space.Compact>
          <Typography.Paragraph type="secondary" className="card-note">
            生产环境请使用 HTTPS，并将管理台来源加入后端 MCP_ALLOWED_ORIGINS。
          </Typography.Paragraph>
        </Card>
        <Card title="MCP Bearer Token">
          <Input.Password value={mcpKey} onChange={(event) => setMcpKey(event.target.value)} placeholder="输入 MCP_API_KEY" />
          <Typography.Paragraph type="secondary" className="card-note">
            该值只用于本页生成客户端示例，不会提交或保存到后端。
          </Typography.Paragraph>
        </Card>
      </div>

      <Card title="客户端配置示例" extra={<Button type="text" icon={<CopyOutlined />} onClick={() => void copy(config)}>复制 JSON</Button>}>
        <pre className="guide-code">{config}</pre>
      </Card>

      <Alert
        className="guide-alert"
        type="info"
        showIcon
        message="认证边界"
        description="客户端到 Gateway 使用 MCP Bearer Token；Gateway 到业务系统则使用每个工具独立配置的 Basic、Bearer 或 API Key。两层凭证不会互相透传。"
      />
    </div>
  )
}
