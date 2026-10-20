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
