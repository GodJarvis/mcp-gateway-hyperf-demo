import React from 'react'
import ReactDOM from 'react-dom/client'
import { App as AntdApp, ConfigProvider, theme } from 'antd'
import zhCN from 'antd/locale/zh_CN'
import App from './App'
import './styles.css'

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <ConfigProvider
      locale={zhCN}
      theme={{
        algorithm: theme.darkAlgorithm,
        token: {
          colorPrimary: '#58e6b0',
          colorInfo: '#58e6b0',
          borderRadius: 10,
          fontFamily: "Inter, 'PingFang SC', 'Microsoft YaHei', sans-serif",
        },
      }}
    >
      <AntdApp>
        <App />
      </AntdApp>
    </ConfigProvider>
  </React.StrictMode>,
)
