<?php

namespace App\Console\Commands;

use App\Actions\Admin\RecordPlatformAudit;
use App\Enums\PlatformAuditAction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The only way to make someone a platform admin: no API request can set the flag.
 * They need two-factor on first, set up by them before the grant (DisableTwoFactor
 * takes the flag away again).
 */
class GrantPlatformAdmin extends Command
{
    protected $signature = 'admin:grant {email : The user\'s email address}';

    protected $description = 'Make a user a platform admin (they need two-factor turned on first)';

    public function handle(RecordPlatformAudit $recordAudit): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user with that email address.');

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled()) {
            $this->error('They need two-factor authentication turned on first (Account settings).');

            return self::FAILURE;
        }

        if ($user->isPlatformAdmin()) {
            $this->info("{$user->email} is already a platform admin.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $recordAudit) {
            $user->forceFill(['is_platform_admin' => true])->save();
            $recordAudit->handle(PlatformAuditAction::AdminGranted, ['type' => 'user', 'id' => $user->id, 'label' => $user->email]);
        });

        $this->info("{$user->email} is now a platform admin.");

        return self::SUCCESS;
    }
}
