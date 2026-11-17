<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $this->hasActiveMembership($user, $organization);
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->hasAnyRole($user, $organization, ['owner', 'admin']);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->hasAnyRole($user, $organization, ['owner', 'admin']);
    }

    public function manageBilling(User $user, Organization $organization): bool
    {
        return $this->hasAnyRole($user, $organization, ['owner', 'admin', 'billing']);
    }

    public function manageNetwork(User $user, Organization $organization): bool
    {
        return $this->hasAnyRole($user, $organization, ['owner', 'admin', 'manager', 'support']);
    }

    private function hasAnyRole(User $user, Organization $organization, array $roles): bool
    {
        if (! $this->hasActiveMembership($user, $organization)) {
            return false;
        }

        return $user->organizations()
            ->wherePivot('status', 'active')
            ->where('organizations.status', 'active')
            ->whereKey($organization->getKey())
            ->wherePivotIn('role', $roles)
            ->exists();
    }

    private function hasActiveMembership(User $user, Organization $organization): bool
    {
        return $user->status === 'active'
            && $organization->status === 'active'
            && $user->organizations()
                ->wherePivot('status', 'active')
                ->where('organizations.status', 'active')
                ->whereKey($organization->getKey())
                ->exists();
    }
}
