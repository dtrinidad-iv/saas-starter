import type { Invitation, Member, Permission, Role, RoleSummary } from '~/types/api'

/**
 * Mirrors the API's member, invitation and role policies, only to decide which
 * controls to show. The API enforces the real rules.
 *
 * Non-owners only act on roles they cover (every permission of the role is one of
 * theirs), so the role-based checks need the organization's roles.
 */
export function useOrganizationPermissions(roles: MaybeRefOrGetter<Role[]> = []) {
  const auth = useAuthStore()
  const organizations = useOrganizationStore()

  const role = computed<RoleSummary | null>(() => organizations.current?.role ?? null)
  const permissions = computed<Permission[]>(() => organizations.current?.permissions ?? [])
  const isOwner = computed(() => role.value?.is_owner ?? false)

  function can(permission: Permission) {
    return permissions.value.includes(permission)
  }

  function isSelf(member: Member) {
    return member.id === auth.user?.id
  }

  function findRole(summary: RoleSummary | null): Role | undefined {
    return summary ? toValue(roles).find(candidate => candidate.id === summary.id) : undefined
  }

  /** Whether the current user can do everything this role can. */
  function covers(target: Role | undefined) {
    if (!target) {
      return false
    }
    if (target.is_owner) {
      return isOwner.value
    }
    return target.permissions.every(can)
  }

  /** covers(), and the current user can do more. */
  function strictlyCovers(target: Role | undefined) {
    return covers(target) && permissions.value.some(permission => !target!.permissions.includes(permission))
  }

  /** Roles the current user may invite someone as. */
  const invitableRoles = computed<Role[]>(() =>
    can('members.invite') ? toValue(roles).filter(covers) : [],
  )

  /** Roles the current user may give this member; empty when they can't change it. */
  function assignableRoles(member: Member): Role[] {
    if (isOwner.value) {
      return toValue(roles)
    }
    if (can('members.change_role') && covers(findRole(member.role))) {
      return toValue(roles).filter(covers)
    }
    return []
  }

  function canRemove(member: Member) {
    if (isSelf(member)) {
      return false // that's "Leave"
    }
    return isOwner.value || (can('members.remove') && strictlyCovers(findRole(member.role)))
  }

  /** Revoke an invitation: only for roles the current user covers (or whose role is gone). */
  function canRevoke(invitation: Invitation) {
    return can('members.invite') && (invitation.role === null || covers(findRole(invitation.role)))
  }

  /** Edit or delete this role. Never the owner role; non-owners only roles that can do less than theirs. */
  function canChangeRole(target: Role) {
    if (!can('roles.manage') || target.is_owner) {
      return false
    }
    return isOwner.value || (target.id !== role.value?.id && strictlyCovers(target))
  }

  return {
    role,
    isOwner,
    can,
    canManageInvitations: computed(() => can('members.invite')),
    canManageRoles: computed(() => can('roles.manage')),
    invitableRoles,
    assignableRoles,
    canRemove,
    canRevoke,
    canChangeRole,
    isSelf,
  }
}
