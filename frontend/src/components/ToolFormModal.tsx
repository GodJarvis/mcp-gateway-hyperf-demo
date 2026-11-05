import { useEffect, useState } from 'react'
import {
  Alert,
  Col,
  Form,
  Input,
  InputNumber,
  Modal,
  Row,
  Select,
  Space,
  Switch,
  Typography,
  message,
} from 'antd'
import type { AuthType, BusinessSystem, HttpMethod, ToolConfig, ToolPayload } from '../types'

interface ToolFormModalProps {
  open: boolean
  tool: ToolConfig | null
  systems: BusinessSystem[]
  onCancel: () => void
  onSubmit: (payload: ToolPayload) => Promise<void>
}

interface FormValues {
  business_system_id: number
  name: string
  description: string
  endpoint_url: string
  http_method: HttpMethod
  timeout_seconds: number
  input_schema_text: string
  output_schema_text: string
  static_headers_text: string
  auth_type: AuthType
  auth_header_name: string
  auth_username?: string
  auth_secret?: string
  enabled: boolean
}

const DEFAULT_INPUT_SCHEMA = {
  type: 'object',
  properties: {
    id: { type: 'string', description: '业务对象 ID' },
  },
  required: ['id'],
  additionalProperties: false,
}

const formatJson = (value: unknown): string => JSON.stringify(value, null, 2)

const parseObjectJson = (
  value: string,
  label: string,
  allowEmpty = false,
): Record<string, unknown> | null => {
  if (allowEmpty && !value.trim()) return null
  let parsed: unknown
  try {
    parsed = JSON.parse(value)
  } catch {
    throw new Error(`${label}不是合法 JSON`)
  }
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    throw new Error(`${label}必须是 JSON 对象`)
  }
  return parsed as Record<string, unknown>
}

export default function ToolFormModal({ open, tool, systems, onCancel, onSubmit }: ToolFormModalProps) {
  const [form] = Form.useForm<FormValues>()
  const [saving, setSaving] = useState(false)
  const authType = Form.useWatch('auth_type', form)

  useEffect(() => {
    if (!open) return
    if (tool) {
      form.setFieldsValue({
        business_system_id: tool.business_system_id,
        name: tool.name,
        description: tool.description,
        endpoint_url: tool.endpoint_url,
        http_method: tool.http_method,
        timeout_seconds: tool.timeout_seconds,
        input_schema_text: formatJson(tool.input_schema),
        output_schema_text: tool.output_schema ? formatJson(tool.output_schema) : '',
        static_headers_text: formatJson(tool.static_headers),
        auth_type: tool.auth_type,
        auth_header_name: tool.auth_header_name,
        auth_username: tool.auth_username ?? undefined,
        auth_secret: '',
        enabled: tool.enabled,
      })
    } else {
      form.resetFields()
      form.setFieldsValue({
        business_system_id: systems.find((item) => item.enabled)?.id,
        http_method: 'POST',
        timeout_seconds: 30,
        input_schema_text: formatJson(DEFAULT_INPUT_SCHEMA),
        output_schema_text: '',
        static_headers_text: '{}',
        auth_type: 'none',
        auth_header_name: 'Authorization',
        enabled: true,
      })
    }
  }, [form, open, systems, tool])

  const handleFinish = async (values: FormValues) => {
    try {
      const inputSchema = parseObjectJson(values.input_schema_text, '入参 Schema')
      const outputSchema = parseObjectJson(values.output_schema_text, '出参 Schema', true)
      const staticHeaders = parseObjectJson(values.static_headers_text, '静态 Header') ?? {}
      if (!Object.values(staticHeaders).every((value) => typeof value === 'string')) {
        throw new Error('静态 Header 的值必须全部是字符串')
      }

      setSaving(true)
      await onSubmit({
        business_system_id: values.business_system_id,
        name: values.name.trim(),
        description: values.description.trim(),
        endpoint_url: values.endpoint_url.trim(),
        http_method: values.http_method,
        timeout_seconds: values.timeout_seconds,
        input_schema: inputSchema ?? {},
        output_schema: outputSchema,
        static_headers: staticHeaders as Record<string, string>,
        auth_type: values.auth_type,
        auth_header_name: values.auth_header_name.trim(),
        auth_username: values.auth_username?.trim() || undefined,
        auth_secret: values.auth_secret || undefined,
        enabled: values.enabled,
      })
      message.success(tool ? '工具配置已更新' : '工具已创建')
      onCancel()
    } catch (error) {
      message.error(error instanceof Error ? error.message : '保存失败')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      title={tool ? `编辑工具 · ${tool.name}` : '新增 HTTP 工具'}
      open={open}
      onCancel={onCancel}
      onOk={() => form.submit()}
      okText="保存配置"
      cancelText="取消"
      confirmLoading={saving}
      width={920}
      destroyOnHidden
    >
      <Alert
        className="form-hint"
        type="info"
        showIcon
        message="MCP 客户端只会看到已启用且所属业务系统已启用的工具。认证密钥加密保存，编辑时留空表示保留原密钥。"
      />
      <Form<FormValues> form={form} layout="vertical" onFinish={handleFinish}>
        <Row gutter={16}>
          <Col span={12}>
            <Form.Item name="business_system_id" label="所属业务系统" rules={[{ required: true }]}>
              <Select
                options={systems.map((system) => ({
                  value: system.id,
                  label: `${system.name}${system.enabled ? '' : '（已停用）'}`,
                }))}
                placeholder="选择业务系统"
              />
            </Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item
              name="name"
              label="工具名称"
              rules={[
                { required: true, message: '请输入工具名称' },
                { pattern: /^[A-Za-z][A-Za-z0-9_.-]{0,127}$/, message: '须以字母开头，仅允许字母、数字、_、.、-' },
              ]}
            >
              <Input placeholder="例如 order_query" />
            </Form.Item>
          </Col>
        </Row>

        <Form.Item name="description" label="工具描述" rules={[{ required: true, message: '请输入工具描述' }]}>
          <Input.TextArea rows={2} placeholder="向大模型准确说明工具用途、适用场景和边界" />
        </Form.Item>

        <Row gutter={16}>
          <Col span={5}>
            <Form.Item name="http_method" label="请求方法" rules={[{ required: true }]}>
              <Select options={['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((value) => ({ value, label: value }))} />
            </Form.Item>
          </Col>
          <Col span={14}>
            <Form.Item name="endpoint_url" label="端点地址" rules={[{ required: true }, { type: 'url', message: '请输入完整 URL' }]}>
              <Input placeholder="https://order.internal/api/orders/query" />
            </Form.Item>
          </Col>
          <Col span={5}>
            <Form.Item name="timeout_seconds" label="超时（秒）" rules={[{ required: true }]}>
              <InputNumber min={1} max={300} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>

        <Typography.Title level={5}>接口契约</Typography.Title>
        <Row gutter={16}>
          <Col span={12}>
            <Form.Item name="input_schema_text" label="入参 JSON Schema" rules={[{ required: true }]}>
              <Input.TextArea className="code-editor" rows={13} spellCheck={false} />
            </Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item name="output_schema_text" label="出参 JSON Schema（可选）">
              <Input.TextArea
                className="code-editor"
                rows={13}
                spellCheck={false}
                placeholder={'留空表示不校验目标接口响应\n{\n  "type": "object"\n}'}
              />
            </Form.Item>
          </Col>
        </Row>

        <Typography.Title level={5}>目标接口认证</Typography.Title>
        <Row gutter={16}>
          <Col span={7}>
            <Form.Item name="auth_type" label="认证类型" rules={[{ required: true }]}>
              <Select
                options={[
                  { value: 'none', label: '无认证' },
                  { value: 'basic', label: 'Basic' },
                  { value: 'bearer', label: 'Bearer' },
                  { value: 'api_key', label: 'API Key' },
                ]}
              />
            </Form.Item>
          </Col>
          <Col span={8}>
            <Form.Item
              name="auth_header_name"
              label="Header 名称"
              rules={[{ required: authType !== 'none', message: '请输入 Header 名称' }]}
            >
              <Input disabled={authType === 'none'} placeholder="Authorization 或 X-API-Key" />
            </Form.Item>
          </Col>
          {authType === 'basic' && (
            <Col span={9}>
              <Form.Item name="auth_username" label="Basic 用户名" rules={[{ required: true }]}>
                <Input autoComplete="off" />
              </Form.Item>
            </Col>
          )}
          {authType !== 'none' && (
            <Col span={authType === 'basic' ? 24 : 9}>
              <Form.Item
                name="auth_secret"
                label={authType === 'basic' ? 'Basic 密码' : authType === 'bearer' ? 'Bearer Token' : 'API Key'}
                rules={[{ required: !tool?.auth_secret_configured, message: '首次配置必须填写认证密钥' }]}
              >
                <Input.Password
                  autoComplete="new-password"
                  placeholder={tool?.auth_secret_configured ? '留空表示保留原密钥' : '请输入认证密钥'}
                />
              </Form.Item>
            </Col>
          )}
        </Row>

        <Row gutter={16} align="bottom">
          <Col span={18}>
            <Form.Item name="static_headers_text" label="静态 Header（JSON 对象）" rules={[{ required: true }]}>
              <Input.TextArea className="code-editor" rows={4} spellCheck={false} placeholder={'{\n  "X-Tenant": "demo"\n}'} />
            </Form.Item>
          </Col>
          <Col span={6}>
            <Form.Item name="enabled" label="发布状态" valuePropName="checked">
              <Space>
                <Switch />
                <Typography.Text type="secondary">启用后加入 tools/list</Typography.Text>
              </Space>
            </Form.Item>
          </Col>
        </Row>
      </Form>
    </Modal>
  )
}
