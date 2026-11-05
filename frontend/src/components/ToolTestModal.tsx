import { useEffect, useState } from 'react'
import { Alert, Button, Input, Modal, Space, Tag, Typography, message } from 'antd'
import { PlayCircleOutlined } from '@ant-design/icons'
import { toolApi } from '../api'
import type { ToolConfig, ToolExecution } from '../types'

interface ToolTestModalProps {
  tool: ToolConfig | null
  open: boolean
  onCancel: () => void
}

export default function ToolTestModal({ tool, open, onCancel }: ToolTestModalProps) {
  const [argumentsText, setArgumentsText] = useState('{}')
  const [result, setResult] = useState<ToolExecution | null>(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (!open || !tool) return
    const properties = (tool.input_schema.properties ?? {}) as Record<string, { type?: string }>
    const example = Object.fromEntries(
      Object.entries(properties).map(([name, schema]) => [
        name,
        schema.type === 'integer' || schema.type === 'number'
          ? 0
          : schema.type === 'boolean'
            ? false
            : schema.type === 'array'
              ? []
              : schema.type === 'object'
                ? {}
                : '',
      ]),
    )
    setArgumentsText(JSON.stringify(example, null, 2))
    setResult(null)
  }, [open, tool])

  const runTest = async () => {
    if (!tool) return
    try {
      const parsed: unknown = JSON.parse(argumentsText)
      if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
        throw new Error('测试参数必须是 JSON 对象')
      }
      setLoading(true)
      setResult(await toolApi.test(tool.id, parsed as Record<string, unknown>))
    } catch (error) {
      message.error(error instanceof Error ? error.message : '测试失败')
    } finally {
      setLoading(false)
    }
  }

  return (
    <Modal title={`试调用 · ${tool?.name ?? ''}`} open={open} onCancel={onCancel} footer={null} width={820} destroyOnHidden>
      <Alert
        type="warning"
        showIcon
        message="试调用会真实请求目标业务系统，并在审计日志中标记为 console_test。"
        className="form-hint"
      />
      <Typography.Text type="secondary">调用参数</Typography.Text>
      <Input.TextArea
        className="code-editor test-editor"
        value={argumentsText}
        onChange={(event) => setArgumentsText(event.target.value)}
        rows={10}
        spellCheck={false}
      />
      <Button type="primary" icon={<PlayCircleOutlined />} loading={loading} onClick={runTest} className="test-button">
        发起真实调用
      </Button>
      {result && (
        <div className={`test-result ${result.success ? 'success' : 'failed'}`}>
          <Space>
            <Tag color={result.success ? 'success' : 'error'}>{result.success ? '成功' : '失败'}</Tag>
            <Typography.Text>HTTP {result.http_status ?? '-'}</Typography.Text>
            <Typography.Text type="secondary">{result.duration_ms} ms</Typography.Text>
          </Space>
          <pre>{JSON.stringify(result.success ? result.data : { error: result.error, data: result.data }, null, 2)}</pre>
        </div>
      )}
    </Modal>
  )
}
