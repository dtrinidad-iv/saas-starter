<?php

namespace App\Actions\Admin;

use App\Enums\PlatformAuditAction;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

class RecordPlatformAudit
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Request $request,
    ) {}

    /**
     * Write a platform audit log entry. Never pass secrets (tokens, passwords) or
     * content of an organization (projects, chats).
     *
     * @param  array{type: string, id: int|null, label: string|null}|null  $subject
     * @param  array<string, mixed>  $metadata
     * @param  User|null  $actor  defaults to the logged-in user (in a "view as user" session
     *                            that's the user being viewed, so pass the admin)
     */
    public function handle(PlatformAuditAction $action, ?array $subject = null, array $metadata = [], ?User $actor = null): PlatformAuditLog
    {
        $user = $actor ?? $this->auth->guard()->user();

        $entry = (new PlatformAuditLog)->forceFill([
            'actor_id' => $user instanceof User ? $user->id : null,
            'action' => $action,
            'subject_type' => $subject['type'] ?? null,
            'subject_id' => $subject['id'] ?? null,
            'subject_label' => isset($subject['label']) ? mb_substr($subject['label'], 0, 255) : null,
            'metadata' => $metadata ?: null,
            // Only for something a logged-in admin did in this request, not the console.
            'ip_address' => $user instanceof User ? $this->request->ip() : null,
        ]);
        $entry->save();

        return $entry;
    }
}
