export type AuthType = 'none' | 'basic' | 'bearer' | 'api_key'
export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export interface BusinessSystem {
  id: number
  name: string
  code: string
  description: string
  enabled: boolean
  tool_count: number
  created_at: string
  updated_at: string
}

export interface BusinessSystemPayload {
  name: string
  code: string
  description: string
  enabled: boolean
}

export interface ToolConfig {
  id: number
  business_system_id: number
  business_system_name: string
  name: string
  description: string
  endpoint_url: string
  http_method: HttpMethod
  timeout_seconds: number
  input_schema: Record<string, unknown>
  output_schema: Record<string, unknown> | null
  static_headers: Record<string, string>
  auth_type: AuthType
  auth_header_name: string
  auth_username: string | null
  auth_secret_configured: boolean
  enabled: boolean
  created_at: string
  updated_at: string
}

export interface ToolPayload {
  business_system_id: number
  name: string
  description: string
  endpoint_url: string
  http_method: HttpMethod
  timeout_seconds: number
  input_schema: Record<string, unknown>
  output_schema: Record<string, unknown> | null
  static_headers: Record<string, string>
  auth_type: AuthType
  auth_header_name: string
  auth_username?: string
  auth_secret?: string
  enabled: boolean
}

export interface ToolExecution {
  success: boolean
  http_status: number | null
  duration_ms: number
  data: unknown
  error: string | null
}

export interface AuditLog {
  id: number
  tool_id: number | null
  tool_name: string
  source: string
  success: boolean
  http_status: number | null
  duration_ms: number
  argument_keys: string[]
  error_message: string | null
  created_at: string
}
