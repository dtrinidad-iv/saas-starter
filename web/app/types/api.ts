export type OrganizationRole = 'owner' | 'admin' | 'member'

export interface Organization {
  id: number
  name: string
  /** The current user's role in this organization. */
  role: OrganizationRole
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
  role: OrganizationRole
  joined_at: string
}

export interface Invitation {
  id: number
  email: string
  role: OrganizationRole
  invited_by: { id: number, name: string } | null
  expires_at: string
  is_expired: boolean
  created_at: string
}

export interface InvitationPreview {
  organization: { name: string }
  invited_by: string | null
  email: string
  role: OrganizationRole
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
  can: { update: boolean, delete: boolean }
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
