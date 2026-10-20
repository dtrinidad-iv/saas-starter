<?php

namespace App\Enums;

/**
 * What an audit log entry records. Model changes come from the Auditable trait
 * ("{auditName}.{event}"); the rest are recorded by their Actions with RecordAudit.
 */
enum AuditAction: string
{
    case OrganizationCreated = 'organization.created';
    case MemberInvited = 'member.invited';
    case InvitationRevoked = 'invitation.revoked';
    case MemberJoined = 'member.joined';
    case MemberRoleChanged = 'member.role_changed';
    case MemberRemoved = 'member.removed';
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RoleDeleted = 'role.deleted';
    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectDeleted = 'project.deleted';
    case SummaryRequested = 'summary.requested';
    case SummaryApproved = 'summary.approved';
    case SummaryRejected = 'summary.rejected';

    public function group(): string
    {
        return match ($this) {
            self::OrganizationCreated => 'Organization',
            self::MemberInvited, self::InvitationRevoked, self::MemberJoined,
            self::MemberRoleChanged, self::MemberRemoved => 'Members',
            self::RoleCreated, self::RoleUpdated, self::RoleDeleted => 'Roles',
            self::ProjectCreated, self::ProjectUpdated, self::ProjectDeleted,
            self::SummaryRequested, self::SummaryApproved, self::SummaryRejected => 'Projects',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OrganizationCreated => 'Created the organization',
            self::MemberInvited => 'Invited someone',
            self::InvitationRevoked => 'Revoked an invitation',
            self::MemberJoined => 'Joined',
            self::MemberRoleChanged => 'Changed a member\'s role',
            self::MemberRemoved => 'Removed a member',
            self::RoleCreated => 'Created a role',
            self::RoleUpdated => 'Edited a role',
            self::RoleDeleted => 'Deleted a role',
            self::ProjectCreated => 'Created a project',
            self::ProjectUpdated => 'Edited a project',
            self::ProjectDeleted => 'Deleted a project',
            self::SummaryRequested => 'Requested a project summary',
            self::SummaryApproved => 'Approved a project summary',
            self::SummaryRejected => 'Rejected a project summary',
        };
    }
}
