<?php

namespace App\Enums;

/**
 * What a role may do in its organization. Roles store these values; the owner
 * role has all of them implicitly (see Role::grants()).
 */
enum Permission: string
{
    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsUpdateOwn = 'projects.update_own';
    case ProjectsUpdateAny = 'projects.update_any';
    case ProjectsDelete = 'projects.delete';
    case MembersInvite = 'members.invite';
    case MembersChangeRole = 'members.change_role';
    case MembersRemove = 'members.remove';
    case RolesManage = 'roles.manage';
    case AuditLogView = 'audit_log.view';
    case AiUse = 'ai.use';
    case AiUsageView = 'ai_usage.view';
    case AiBudgetManage = 'ai_budget.manage';
    case BillingManage = 'billing.manage';

    /**
     * Unique and in catalogue order, so stored roles compare and display consistently.
     *
     * @param  iterable<self>  $permissions
     * @return list<self>
     */
    public static function sorted(iterable $permissions): array
    {
        $permissions = collect($permissions);

        return array_values(array_filter(self::cases(), fn (self $permission) => $permissions->contains($permission)));
    }

    public function group(): string
    {
        return match ($this) {
            self::ProjectsView, self::ProjectsCreate, self::ProjectsUpdateOwn,
            self::ProjectsUpdateAny, self::ProjectsDelete => 'Projects',
            self::MembersInvite, self::MembersChangeRole, self::MembersRemove => 'Members',
            self::RolesManage => 'Roles',
            self::AuditLogView, self::BillingManage => 'Organization',
            self::AiUse, self::AiUsageView, self::AiBudgetManage => 'AI',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ProjectsView => 'View projects',
            self::ProjectsCreate => 'Create projects',
            self::ProjectsUpdateOwn => 'Edit projects they created',
            self::ProjectsUpdateAny => 'Edit any project',
            self::ProjectsDelete => 'Delete projects',
            self::MembersInvite => 'Invite people and revoke invitations',
            self::MembersChangeRole => 'Change members\' roles',
            self::MembersRemove => 'Remove members',
            self::RolesManage => 'Create, edit and delete roles',
            self::AuditLogView => 'View the audit log',
            self::AiUse => 'Use AI features (summaries, writing help, chat)',
            self::AiUsageView => 'View AI usage and cost',
            self::AiBudgetManage => 'Change the monthly AI budget',
            self::BillingManage => 'Manage the plan, payment details and invoices',
        };
    }
}
