import { useState } from 'react'
import {
  ApiOutlined,
  AppstoreOutlined,
  BookOutlined,
  KeyOutlined,
  MenuFoldOutlined,
  MenuUnfoldOutlined,
  SafetyCertificateOutlined,
} from '@ant-design/icons'
import { Button, Form, Input, Layout, Menu, Modal, Space, Tag, Typography, message } from 'antd'
import type { MenuProps } from 'antd'
import { getAdminKey, setAdminKey } from './api'
import AuditPage from './pages/AuditPage'
import GuidePage from './pages/GuidePage'
import SystemsPage from './pages/SystemsPage'
import ToolsPage from './pages/ToolsPage'

type PageKey = 'tools' | 'systems' | 'audit' | 'guide'

const pageTitle: Record<PageKey, string> = {
  tools: '工具配置',
  systems: '业务系统',
  audit: '调用审计',
  guide: '接入说明',
}

export default function App() {
  const [collapsed, setCollapsed] = useState(false)
  const [currentPage, setCurrentPage] = useState<PageKey>('tools')
  const [settingsOpen, setSettingsOpen] = useState(false)
  const [adminKeyInput, setAdminKeyInput] = useState(getAdminKey())

  const menuItems: MenuProps['items'] = [
    { key: 'tools', icon: <ApiOutlined />, label: '工具配置' },
    { key: 'systems', icon: <AppstoreOutlined />, label: '业务系统' },
    { key: 'audit', icon: <SafetyCertificateOutlined />, label: '调用审计' },
    { key: 'guide', icon: <BookOutlined />, label: '接入说明' },
  ]

  const content = {
    tools: <ToolsPage />,
    systems: <SystemsPage />,
    audit: <AuditPage />,
    guide: <GuidePage />,
  }[currentPage]

  return (
    <Layout className="app-shell">
      <Layout.Sider width={240} collapsedWidth={78} collapsed={collapsed} className="app-sider">
        <div className={`brand ${collapsed ? 'collapsed' : ''}`}>
          <div className="brand-mark">M</div>
          {!collapsed && (
            <div>
              <div className="brand-name">MCP Gateway</div>
              <div className="brand-subtitle">Enterprise Console</div>
            </div>
          )}
        </div>
        <Menu
          mode="inline"
          theme="dark"
          selectedKeys={[currentPage]}
          items={menuItems}
          onClick={({ key }) => setCurrentPage(key as PageKey)}
          className="app-menu"
        />
        <div className="sider-status">
          {!collapsed && <Typography.Text type="secondary">Streamable HTTP</Typography.Text>}
          <Tag color="success">ONLINE</Tag>
        </div>
      </Layout.Sider>
      <Layout>
        <Layout.Header className="app-header">
          <Space>
            <Button
              type="text"
              icon={collapsed ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />}
              onClick={() => setCollapsed((value) => !value)}
            />
            <div>
              <Typography.Title level={4}>{pageTitle[currentPage]}</Typography.Title>
              <Typography.Text type="secondary">统一管理和发布企业 HTTP 业务能力</Typography.Text>
            </div>
          </Space>
          <Button icon={<KeyOutlined />} onClick={() => setSettingsOpen(true)}>管理凭证</Button>
        </Layout.Header>
        <Layout.Content className="app-content">{content}</Layout.Content>
      </Layout>

      <Modal
        title="管理端凭证"
        open={settingsOpen}
        onCancel={() => setSettingsOpen(false)}
        onOk={() => {
          setAdminKey(adminKeyInput.trim())
          message.success('管理凭证已保存在当前浏览器')
          setSettingsOpen(false)
        }}
      >
        <Form layout="vertical">
          <Form.Item label="Admin Bearer Token" extra="仅保存在浏览器 localStorage，每次管理 API 请求通过 Authorization Header 发送。">
            <Input.Password value={adminKeyInput} onChange={(event) => setAdminKeyInput(event.target.value)} />
          </Form.Item>
        </Form>
      </Modal>
    </Layout>
  )
}
