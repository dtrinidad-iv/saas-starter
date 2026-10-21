<?php

namespace App\Console\Commands;

use App\Actions\Admin\RecordPlatformAudit;
use App\Enums\PlatformAuditAction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RevokePlatformAdmin extends Command
{
    protected $signature = 'admin:revoke {email : The user\'s email address}';

    protected $description = 'Stop a user being a platform admin (takes effect on their next request)';

    public function handle(RecordPlatformAudit $recordAudit): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user with that email address.');

            return self::FAILURE;
        }

        if (! $user->isPlatformAdmin()) {
            $this->info("{$user->email} isn't a platform admin.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $recordAudit) {
            $user->forceFill(['is_platform_admin' => false])->save();
            $recordAudit->handle(PlatformAuditAction::AdminRevoked, ['type' => 'user', 'id' => $user->id, 'label' => $user->email]);
        });

        $this->info("{$user->email} is no longer a platform admin.");

        return self::SUCCESS;
    }
}
