<?php

namespace App\Models\Concerns;

trait HasRoles
{
    /**
     * Accepts string or array of roles. Returns true if user has any.
     */
    public function hasRole($roles): bool
    {
        if (! $roles) return true;

        // Normalize roles to array
        $roles = is_array($roles) ? $roles : explode(',', (string) $roles);
        $roles = array_values(array_filter(array_map('trim', $roles)));

        if (empty($roles)) return true;

        // Support single role string or roles array on user
        $userRoles = $this->role ?? null;

        // If arrayable/json column (e.g., roles[])
        if (is_array($userRoles)) {
            return (bool) array_intersect($roles, $userRoles);
        }

        // If single role string
        return in_array((string) $userRoles, $roles, true);
    }
}
