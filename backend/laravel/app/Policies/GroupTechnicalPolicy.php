<?php

namespace App\Policies;

use App\Models\GroupAuthority;
use App\Models\User;

class GroupTechnicalPolicy
{
    /**
     * Technical capabilities granted to the Nexora Group IT Manager.
     *
     * Keep this allow-list narrow. Unknown capabilities are denied by default.
     * Financial, governance, staff-identity approval and executive appointment
     * powers are deliberately excluded.
     */
    private const CAPABILITIES = [
        'users.directory.view',
        'system_health.view',
        'integrations.manage',
        'security_events.view',
        'backups.jobs.manage',
        'deployments.manage',
        'audit_logs.view',
    ];

    public function allows(User $user, string $capability): bool
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            return false;
        }

        if ($user->status !== 'active') {
            return false;
        }

        return GroupAuthority::query()
            ->where('user_id', $user->id)
            ->where('authority', 'GROUP_IT_MANAGER')
            ->where('scope_type', 'group')
            ->where('scope_key', 'nexora')
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}
