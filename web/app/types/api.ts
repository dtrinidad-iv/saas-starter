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
    | 'billing.manage'

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
  email_verified: boolean
  two_factor_enabled: boolean
  current_organization: Organization | null
  created_at: string
}

export interface AuthResponse {
  user: User
  token: string
}

/** Login or password reset when two-factor is on: swap the challenge for a token with a code. */
export interface TwoFactorChallengeResponse {
  two_factor: true
  challenge: string
}

export type LoginResponse = AuthResponse | TwoFactorChallengeResponse

/** A code from the authenticator app, or a recovery code instead. */
export type TwoFactorChallengePayload = { code: string } | { recovery_code: string }

/** A started two-factor setup: what the authenticator app needs. */
export interface TwoFactorSetup {
  secret: string
  otpauth_uri: string
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

export interface ResetPasswordPayload {
  token: string
  email: string
  password: string
  password_confirmation: string
}

/** One of the user's API tokens, shown as a signed-in device. */
export interface Device {
  id: number
  name: string
  ip_address: string | null
  last_used_at: string | null
  created_at: string
  is_current: boolean
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
  /** The organization's own budget (without credits); null when the plan's applies. */
  own_limit_usd: number | null
  /** Plan key from config/billing.php, e.g. free or pro. */
  plan: string
  /** The most the plan allows; an own budget can only be lower. */
  plan_limit_usd: number
  /** AI credits bought this month, on top of the budget. */
  credits_usd: number
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

/** A plan from config/billing.php. */
export interface BillingPlan {
  key: string
  name: string
  display_price: string
  ai_monthly_budget_usd: number
  is_paid: boolean
}

/** A one-time AI credit pack from config/billing.php. */
export interface CreditPack {
  key: string
  name: string
  display_price: string
  amount_usd: number
  /** False until its Stripe price is configured. */
  available: boolean
}

/** GET /api/billing (needs billing.manage). */
export interface BillingOverview {
  plan: BillingPlan
  subscription: {
    /** Stripe status: active, trialing, past_due, canceled, incomplete... */
    status: string
    /** Set once canceled: the plan stays until then. */
    ends_at: string | null
    on_grace_period: boolean
  } | null
  payment_method: { type: string, last_four: string | null } | null
  /** A Stripe customer exists, so the billing portal can open. */
  has_billing_account: boolean
  plans: BillingPlan[]
  credit_packs: CreditPack[]
  ai_budget: Omit<AiBudget, 'can_manage'>
}

/** Drafts are never listed; open means not paid yet. */
export type InvoiceStatus = 'paid' | 'open' | 'void' | 'uncollectible'

/** GET /api/billing/invoices (needs billing.manage), read live from Stripe. */
export interface Invoice {
  id: string
  number: string | null
  status: InvoiceStatus
  /** In the currency's smallest unit (cents). */
  total: number
  currency: string
  description: string | null
  created_at: string
  /** Stripe's hosted invoice page (pay an open one there). Only Stripe URLs. */
  hosted_url: string | null
  pdf_url: string | null
}

export interface InvoiceList {
  data: Invoice[]
  /** Pass as starting_after for the next page; null on the last one. */
  meta: { next_cursor: string | null }
}
