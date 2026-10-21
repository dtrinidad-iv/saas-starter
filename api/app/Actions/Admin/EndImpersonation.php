<?php

namespace App\Actions\Admin;

use App\Enums\PlatformAuditAction;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EndImpersonation
{
    public function __construct(private readonly RecordPlatformAudit $recordAudit) {}

    /**
     * End a "view as user" session (Stop, or logging out in it): delete its token and
     * record it in the platform log as the admin's. An expired session just ends; the
     * start entry has its expiry.
     */
    public function handle(PersonalAccessToken $token): void
    {
        DB::transaction(function () use ($token) {
            if (PersonalAccessToken::query()->whereKey($token->getKey())->delete() === 0) {
                return;
            }

            /** @var User $user */
            $user = $token->tokenable;

            $this->recordAudit->handle(
                PlatformAuditAction::ImpersonationEnded,
                ['type' => 'user', 'id' => $user->id, 'label' => $user->email],
                ['token_id' => $token->id],
                actor: $token->impersonator,
            );
        });
    }
}
