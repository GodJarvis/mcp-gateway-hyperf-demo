import axios from 'axios'
import type {
  AuditLog,
  BusinessSystem,
  BusinessSystemPayload,
  ToolConfig,
  ToolExecution,
  ToolPayload,
} from './types'

const ADMIN_KEY_STORAGE = 'mcp-gateway-admin-key'

export const getAdminKey = (): string =>
  localStorage.getItem(ADMIN_KEY_STORAGE) ?? import.meta.env.VITE_ADMIN_API_KEY ?? 'dev-admin-key'

export const setAdminKey = (key: string): void => {
  localStorage.setItem(ADMIN_KEY_STORAGE, key)
}

const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '',
  timeout: 30_000,
})

client.interceptors.request.use((config) => {
  config.headers.Authorization = `Bearer ${getAdminKey()}`
  return config
})

client.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    if (axios.isAxiosError(error)) {
      const detail = error.response?.data?.detail
      const message = typeof detail === 'string' ? detail : error.message
      return Promise.reject(new Error(message))
    }
    return Promise.reject(error)
  },
)

export const systemApi = {
  list: async (): Promise<BusinessSystem[]> => (await client.get('/api/systems')).data,
  create: async (payload: BusinessSystemPayload): Promise<BusinessSystem> =>
    (await client.post('/api/systems', payload)).data,
  update: async (id: number, payload: BusinessSystemPayload): Promise<BusinessSystem> =>
    (await client.put(`/api/systems/${id}`, payload)).data,
  setStatus: async (id: number, enabled: boolean): Promise<BusinessSystem> =>
    (await client.patch(`/api/systems/${id}/status`, { enabled })).data,
  remove: async (id: number): Promise<void> => {
    await client.delete(`/api/systems/${id}`)
  },
}

export const toolApi = {
  list: async (): Promise<ToolConfig[]> => (await client.get('/api/tools')).data,
  create: async (payload: ToolPayload): Promise<ToolConfig> =>
    (await client.post('/api/tools', payload)).data,
  update: async (id: number, payload: ToolPayload): Promise<ToolConfig> =>
    (await client.put(`/api/tools/${id}`, payload)).data,
  setStatus: async (id: number, enabled: boolean): Promise<ToolConfig> =>
    (await client.patch(`/api/tools/${id}/status`, { enabled })).data,
  remove: async (id: number): Promise<void> => {
    await client.delete(`/api/tools/${id}`)
  },
  test: async (id: number, argumentsValue: Record<string, unknown>): Promise<ToolExecution> =>
    (await client.post(`/api/tools/${id}/test`, { arguments: argumentsValue })).data,
}

export const auditApi = {
  list: async (): Promise<AuditLog[]> => (await client.get('/api/audit-logs?limit=200')).data,
}
