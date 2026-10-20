import type { Member, OrganizationRole } from '~/types/api'

/**
 * Mirrors the API's member/invitation policies, only to decide which controls
 * to show. The API enforces the real rules.
 */
export function useOrganizationPermissions() {
  const auth = useAuthStore()
  const organizations = useOrganizationStore()

  const role = computed<OrganizationRole | null>(() => organizations.current?.role ?? null)
  const isOwner = computed(() => role.value === 'owner')
  const isOwnerOrAdmin = computed(() => role.value === 'owner' || role.value === 'admin')

  function isSelf(member: Member) {
    return member.id === auth.user?.id
  }

  /** Roles the current user may invite someone as. */
  const invitableRoles = computed<OrganizationRole[]>(() =>
    isOwner.value ? ['member', 'admin', 'owner'] : isOwnerOrAdmin.value ? ['member', 'admin'] : [],
  )

  /** Roles the current user may give this member; empty when they can't change it. */
  function assignableRoles(member: Member): OrganizationRole[] {
    if (isOwner.value) {
      return ['member', 'admin', 'owner']
    }
    if (role.value === 'admin' && member.role !== 'owner') {
      return ['member', 'admin']
    }
    return []
  }

  function canRemove(member: Member) {
    if (isSelf(member)) {
      return false // that's "Leave"
    }
    return isOwner.value || (role.value === 'admin' && member.role === 'member')
  }

  return {
    role,
    canManageInvitations: isOwnerOrAdmin,
    invitableRoles,
    assignableRoles,
    canRemove,
    isSelf,
  }
}
