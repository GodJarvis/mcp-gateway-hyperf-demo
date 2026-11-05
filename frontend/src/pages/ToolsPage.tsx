import { useEffect, useMemo, useState } from 'react'
import {
  ApiOutlined,
  DeleteOutlined,
  EditOutlined,
  ExperimentOutlined,
  PlusOutlined,
  ReloadOutlined,
} from '@ant-design/icons'
import { Button, Input, Popconfirm, Space, Switch, Table, Tag, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { systemApi, toolApi } from '../api'
import ToolFormModal from '../components/ToolFormModal'
import ToolTestModal from '../components/ToolTestModal'
import type { BusinessSystem, ToolConfig, ToolPayload } from '../types'

const authLabel: Record<ToolConfig['auth_type'], string> = {
  none: '无认证',
  basic: 'Basic',
  bearer: 'Bearer',
  api_key: 'API Key',
}

export default function ToolsPage() {
  const [tools, setTools] = useState<ToolConfig[]>([])
  const [systems, setSystems] = useState<BusinessSystem[]>([])
  const [loading, setLoading] = useState(false)
  const [keyword, setKeyword] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<ToolConfig | null>(null)
  const [testing, setTesting] = useState<ToolConfig | null>(null)

  const load = async () => {
    try {
      setLoading(true)
      const [toolRows, systemRows] = await Promise.all([toolApi.list(), systemApi.list()])
      setTools(toolRows)
      setSystems(systemRows)
    } catch (error) {
      message.error(error instanceof Error ? error.message : '加载失败')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
  }, [])

  const filteredTools = useMemo(() => {
    const normalized = keyword.trim().toLowerCase()
    if (!normalized) return tools
    return tools.filter((tool) =>
      [tool.name, tool.description, tool.business_system_name, tool.endpoint_url]
        .some((value) => value.toLowerCase().includes(normalized)),
    )
  }, [keyword, tools])

  const saveTool = async (payload: ToolPayload) => {
    if (editing) await toolApi.update(editing.id, payload)
    else await toolApi.create(payload)
    await load()
  }

  const columns: ColumnsType<ToolConfig> = [
    {
      title: '工具',
      key: 'tool',
      width: 250,
      render: (_, row) => (
        <div className="tool-cell">
          <div className="tool-icon"><ApiOutlined /></div>
          <div>
            <Typography.Text strong copyable>{row.name}</Typography.Text>
            <div><Typography.Text type="secondary" ellipsis>{row.description}</Typography.Text></div>
          </div>
        </div>
      ),
    },
    { title: '业务系统', dataIndex: 'business_system_name', width: 140 },
    {
      title: '目标端点',
      key: 'endpoint',
      render: (_, row) => (
        <div>
          <Tag color={row.http_method === 'GET' ? 'cyan' : 'geekblue'}>{row.http_method}</Tag>
          <Typography.Text className="endpoint-text" title={row.endpoint_url}>{row.endpoint_url}</Typography.Text>
        </div>
      ),
    },
    {
      title: '认证',
      dataIndex: 'auth_type',
      width: 100,
      render: (value: ToolConfig['auth_type']) => <Tag color={value === 'none' ? 'default' : 'gold'}>{authLabel[value]}</Tag>,
    },
    {
      title: '状态',
      dataIndex: 'enabled',
      width: 90,
      render: (enabled: boolean, row) => (
        <Switch
          size="small"
          checked={enabled}
          onChange={async (checked) => {
            try {
              await toolApi.setStatus(row.id, checked)
              await load()
            } catch (error) {
              message.error(error instanceof Error ? error.message : '状态更新失败')
            }
          }}
        />
      ),
    },
    {
      title: '操作',
      key: 'actions',
      width: 155,
      fixed: 'right',
      render: (_, row) => (
        <Space size={2}>
          <Button type="text" title="试调用" icon={<ExperimentOutlined />} onClick={() => setTesting(row)} />
          <Button
            type="text"
            title="编辑"
            icon={<EditOutlined />}
            onClick={() => {
              setEditing(row)
              setFormOpen(true)
            }}
          />
          <Popconfirm
            title="确认删除该工具？"
            onConfirm={async () => {
              try {
                await toolApi.remove(row.id)
                message.success('工具已删除')
                await load()
              } catch (error) {
                message.error(error instanceof Error ? error.message : '删除失败')
              }
            }}
          >
            <Button type="text" danger title="删除" icon={<DeleteOutlined />} />
          </Popconfirm>
        </Space>
      ),
    },
  ]

  return (
    <div className="page-card">
      <div className="page-toolbar">
        <div>
          <Typography.Title level={3}>HTTP 工具配置</Typography.Title>
          <Typography.Text type="secondary">数据库配置会实时映射为 MCP tools/list，调用时由 Gateway 注入认证并转发。</Typography.Text>
        </div>
        <Space>
          <Button icon={<ReloadOutlined />} onClick={() => void load()}>刷新</Button>
          <Button
            type="primary"
            icon={<PlusOutlined />}
            disabled={systems.length === 0}
            onClick={() => {
              setEditing(null)
              setFormOpen(true)
            }}
          >
            新增工具
          </Button>
        </Space>
      </div>
      <div className="table-filter">
        <Input.Search
          allowClear
          value={keyword}
          onChange={(event) => setKeyword(event.target.value)}
          placeholder="搜索工具名称、描述、系统或端点"
          style={{ width: 380 }}
        />
        <Typography.Text type="secondary">共 {filteredTools.length} 个工具</Typography.Text>
      </div>
      <Table
        rowKey="id"
        columns={columns}
        dataSource={filteredTools}
        loading={loading}
        pagination={{ pageSize: 10, showSizeChanger: false }}
        scroll={{ x: 1050 }}
      />

      <ToolFormModal
        open={formOpen}
        tool={editing}
        systems={systems}
        onCancel={() => setFormOpen(false)}
        onSubmit={saveTool}
      />
      <ToolTestModal tool={testing} open={testing !== null} onCancel={() => setTesting(null)} />
    </div>
  )
}
