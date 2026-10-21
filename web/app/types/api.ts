/** Mirrors App\Enums\Permission. */
export type Permission
  = | 'projects.view'
    | 'projects.create'
    | 'projects.update_own'
    | 'projects.update_any'
    | 'projects.delete'
    | 'members.invite'
    | 'members.change_role'
    | 'members.remove'
    | 'roles.manage'
    | 'audit_log.view'
    | 'ai.use'
    | 'ai_usage.view'
    | 'ai_budget.manage'

/** An entry of GET /api/permissions. */
export interface PermissionInfo {
  value: Permission
  group: string
  label: string
}

/** The short form of a role, as shown on members, invitations and organizations. */
export interface RoleSummary {
  id: number
  name: string
  /** The built-in owner role: every permission, can't be edited or deleted. */
  is_owner: boolean
}

export interface Role extends RoleSummary {
  /** Everything the role grants (all permissions for the owner role). */
  permissions: Permission[]
  members_count: number
  created_at: string
}

export interface RolePayload {
  name: string
  permissions: Permission[]
}

export interface Organization {
  id: number
  name: string
  /** The current user's role in this organization. */
  role: RoleSummary
  /** What that role grants. */
  permissions: Permission[]
  created_at: string
}

export interface User {
  id: number
  name: string
  email: string
  current_organization: Organization | null
  created_at: string
}

export interface AuthResponse {
  user: User
  token: string
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
}

export interface LoginPayload {
  email: string
  password: string
}

export interface Member {
  id: number
  name: string
  email: string
  role: RoleSummary
  joined_at: string
}

export interface Invitation {
  id: number
  email: string
  /** Null once the role was deleted (the invitation can't be accepted then). */
  role: RoleSummary | null
  invited_by: { id: number, name: string } | null
  expires_at: string
  is_expired: boolean
  created_at: string
}

export interface InvitationPreview {
  organization: { name: string }
  invited_by: string | null
  email: string
  role: { name: string }
  expires_at: string
}

export interface Project {
  id: number
  name: string
  description: string | null
  creator: { id: number, name: string } | null
  created_at: string
  updated_at: string
  /** What the current user may do, decided by the API. */
  can: { update: boolean, delete: boolean, chat: boolean }
}

export interface ProjectPayload {
  name: string
  description: string | null
}

/** Laravel paginated resource collection. */
export interface Paginated<T> {
  data: T[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

export type ProjectSummaryStatus = 'pending' | 'draft' | 'approved' | 'rejected' | 'failed'

/** An AI-generated project summary. Only an approved one is the project's summary. */
export interface ProjectSummary {
  id: number
  project_id: number
  status: ProjectSummaryStatus
  summary: string | null
  key_points: string[]
  model: string | null
  /** The project's name or description changed since this summary was written. */
  stale: boolean
  /** A short, user-facing reason, only when status is "failed". */
  error: string | null
  requested_by: { id: number, name: string } | null
  reviewed_by: { id: number, name: string } | null
  reviewed_at: string | null
  created_at: string
  updated_at: string
  /** What the current user may do, decided by the API. */
  can: { review: boolean, regenerate: boolean }
}

/** A reviewer's edits, sent when approving a draft. */
export interface ProjectSummaryEdits {
  summary: string
  key_points: string[]
}

/** An entry of GET /api/audit-log/actions (mirrors App\Enums\AuditAction). */
export interface AuditActionInfo {
  value: string
  group: string
  label: string
}

/** One entry of the organization's audit log. */
export interface AuditLogEntry {
  id: number
  action: string
  action_label: string
  /** Null for changes made without a user (jobs) or by a deleted user. */
  actor: { id: number, name: string } | null
  /** What was acted on; the label is its name at the time. */
  subject: { type: string, id: number | null, label: string | null } | null
  changes: Record<string, { old: unknown, new: unknown }>
  metadata: Record<string, unknown>
  /** Only sent to owners. */
  ip_address?: string | null
  created_at: string
}

/** Calls, tokens and cost for one slice of GET /api/ai-usage. */
export interface AiUsageTotals {
  calls: number
  failed: number
  input_tokens: number
  output_tokens: number
  cost_usd: number
}

/** The current month's AI budget. limit 0 means AI is turned off. */
export interface AiBudget {
  limit_usd: number
  /** No own budget: the plan's applies. */
  is_default: boolean
  /** Plan key from config/billing.php, e.g. free or pro. */
  plan: string
  /** The most the plan allows; an own budget can only be lower. */
  plan_limit_usd: number
  spent_usd: number
  remaining_usd: number
  can_manage: boolean
}

/** GET /api/ai-usage?month=YYYY-MM */
export interface AiUsageReport {
  month: string
  totals: AiUsageTotals
  by_feature: (AiUsageTotals & { feature: string })[]
  by_user: (AiUsageTotals & { user: { id: number, name: string } | null })[]
  by_day: (AiUsageTotals & { day: string })[]
  budget: AiBudget
}

export type ChatMessageRole = 'user' | 'assistant'

/** complete; or for an answer: stopped (the user cancelled) or failed. Both keep the text that arrived. */
export type ChatMessageStatus = 'complete' | 'failed' | 'stopped'

/** One message of the current user's private AI chat about a project. */
export interface ProjectChatMessage {
  id: number
  role: ChatMessageRole
  content: string
  status: ChatMessageStatus
  model: string | null
  created_at: string
}
