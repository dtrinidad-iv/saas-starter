<?php

namespace App\Enums;

/**
 * What a platform audit log entry records (RecordPlatformAudit). Only platform
 * admins see these; organizations never do.
 */
enum PlatformAuditAction: string
{
    case AdminGranted = 'admin.granted';
    case AdminRevoked = 'admin.revoked';
    case UserViewed = 'admin.user_viewed';
    case OrganizationViewed = 'admin.organization_viewed';

    public function label(): string
    {
        return match ($this) {
            self::AdminGranted => 'Made a platform admin',
            self::AdminRevoked => 'Removed a platform admin',
            self::UserViewed => 'Viewed a user',
            self::OrganizationViewed => 'Viewed an organization',
        };
    }
}
