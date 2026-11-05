import { useEffect, useState } from 'react'
import { ReloadOutlined } from '@ant-design/icons'
import { Button, Space, Table, Tag, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import dayjs from 'dayjs'
import { auditApi } from '../api'
import type { AuditLog } from '../types'

export default function AuditPage() {
  const [rows, setRows] = useState<AuditLog[]>([])
  const [loading, setLoading] = useState(false)

  const load = async () => {
    try {
      setLoading(true)
      setRows(await auditApi.list())
    } catch (error) {
      message.error(error instanceof Error ? error.message : '加载审计日志失败')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
  }, [])

  const columns: ColumnsType<AuditLog> = [
    { title: '时间', dataIndex: 'created_at', width: 180, render: (value: string) => dayjs(value).format('YYYY-MM-DD HH:mm:ss') },
    { title: '工具', dataIndex: 'tool_name', width: 180, render: (value: string) => <Typography.Text code>{value}</Typography.Text> },
    { title: '来源', dataIndex: 'source', width: 120, render: (value: string) => <Tag>{value}</Tag> },
    {
      title: '结果',
      dataIndex: 'success',
      width: 90,
      render: (value: boolean) => <Tag color={value ? 'success' : 'error'}>{value ? '成功' : '失败'}</Tag>,
    },
    { title: 'HTTP', dataIndex: 'http_status', width: 80, render: (value: number | null) => value ?? '-' },
    { title: '耗时', dataIndex: 'duration_ms', width: 100, render: (value: number) => `${value} ms` },
    { title: '参数字段', dataIndex: 'argument_keys', width: 220, render: (values: string[]) => values.map((value) => <Tag key={value}>{value}</Tag>) },
    { title: '错误', dataIndex: 'error_message', ellipsis: true, render: (value: string | null) => value || '-' },
  ]

  return (
    <div className="page-card">
      <div className="page-toolbar">
        <div>
          <Typography.Title level={3}>调用审计</Typography.Title>
          <Typography.Text type="secondary">只记录参数字段名，不持久化参数值和目标接口响应，避免敏感数据进入日志。</Typography.Text>
        </div>
        <Button icon={<ReloadOutlined />} onClick={() => void load()}>刷新</Button>
      </div>
      <Table rowKey="id" columns={columns} dataSource={rows} loading={loading} scroll={{ x: 1100 }} pagination={{ pageSize: 20 }} />
    </div>
  )
}
