import { useEffect, useState } from 'react'
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons'
import { Button, Form, Input, Modal, Popconfirm, Space, Switch, Table, Tag, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { systemApi } from '../api'
import type { BusinessSystem, BusinessSystemPayload } from '../types'

export default function SystemsPage() {
  const [systems, setSystems] = useState<BusinessSystem[]>([])
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [editing, setEditing] = useState<BusinessSystem | null>(null)
  const [modalOpen, setModalOpen] = useState(false)
  const [form] = Form.useForm<BusinessSystemPayload>()

  const load = async () => {
    try {
      setLoading(true)
      setSystems(await systemApi.list())
    } catch (error) {
      message.error(error instanceof Error ? error.message : '加载失败')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
  }, [])

  const openForm = (system: BusinessSystem | null) => {
    setEditing(system)
    setModalOpen(true)
    form.setFieldsValue(
      system
        ? { name: system.name, code: system.code, description: system.description, enabled: system.enabled }
        : { name: '', code: '', description: '', enabled: true },
    )
  }

  const save = async (values: BusinessSystemPayload) => {
    try {
      setSaving(true)
      if (editing) await systemApi.update(editing.id, values)
      else await systemApi.create(values)
      message.success(editing ? '业务系统已更新' : '业务系统已创建')
      setModalOpen(false)
      await load()
    } catch (error) {
      message.error(error instanceof Error ? error.message : '保存失败')
    } finally {
      setSaving(false)
    }
  }

  const columns: ColumnsType<BusinessSystem> = [
    {
      title: '业务系统',
      key: 'name',
      render: (_, row) => (
        <div>
          <Typography.Text strong>{row.name}</Typography.Text>
          <div><Typography.Text type="secondary">{row.code}</Typography.Text></div>
        </div>
      ),
    },
    { title: '描述', dataIndex: 'description', ellipsis: true },
    { title: '工具数量', dataIndex: 'tool_count', width: 100, render: (value: number) => <Tag>{value}</Tag> },
    {
      title: '状态',
      dataIndex: 'enabled',
      width: 100,
      render: (enabled: boolean, row) => (
        <Switch
          size="small"
          checked={enabled}
          onChange={async (checked) => {
            try {
              await systemApi.setStatus(row.id, checked)
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
      width: 150,
      render: (_, row) => (
        <Space>
          <Button type="text" icon={<EditOutlined />} onClick={() => openForm(row)} />
          <Popconfirm
            title="删除业务系统？"
            description={row.tool_count > 0 ? '该系统下仍有工具，服务端会拒绝删除。' : undefined}
            onConfirm={async () => {
              try {
                await systemApi.remove(row.id)
                message.success('业务系统已删除')
                await load()
              } catch (error) {
                message.error(error instanceof Error ? error.message : '删除失败')
              }
            }}
          >
            <Button type="text" danger icon={<DeleteOutlined />} />
          </Popconfirm>
        </Space>
      ),
    },
  ]

  return (
    <div className="page-card">
      <div className="page-toolbar">
        <div>
          <Typography.Title level={3}>业务系统</Typography.Title>
          <Typography.Text type="secondary">对工具按所属系统分组，停用系统会同时从 MCP 工具列表隐藏其全部工具。</Typography.Text>
        </div>
        <Button type="primary" icon={<PlusOutlined />} onClick={() => openForm(null)}>新增业务系统</Button>
      </div>
      <Table rowKey="id" columns={columns} dataSource={systems} loading={loading} pagination={false} />

      <Modal
        title={editing ? '编辑业务系统' : '新增业务系统'}
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={() => form.submit()}
        confirmLoading={saving}
        destroyOnHidden
      >
        <Form form={form} layout="vertical" onFinish={save}>
          <Form.Item name="name" label="系统名称" rules={[{ required: true }]}><Input placeholder="订单中心" /></Form.Item>
          <Form.Item
            name="code"
            label="系统编码"
            rules={[{ required: true }, { pattern: /^[a-z][a-z0-9_-]{1,49}$/, message: '小写字母开头，仅允许小写字母、数字、_、-' }]}
          >
            <Input placeholder="order_center" />
          </Form.Item>
          <Form.Item name="description" label="描述"><Input.TextArea rows={3} /></Form.Item>
          <Form.Item name="enabled" label="状态" valuePropName="checked"><Switch checkedChildren="启用" unCheckedChildren="停用" /></Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
