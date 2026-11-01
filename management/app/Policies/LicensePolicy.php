<?php

namespace App\Policies;

use App\Models\License;
use App\Models\LicenseActivationKey;
use App\Models\User;

class LicensePolicy
{
    public function administer(User $user): bool
    {
        return $user->is_admin || $user->role === 'license_admin';
    }

    public function viewAny(User $user): bool
    {
        return $this->administer($user) || $user->is_admin;
    }

    public function manageKeys(User $user): bool
    {
        return $this->administer($user);
    }

    public function activate(User $user): bool
    {
        return $user->is_admin || in_array($user->role, ['license_admin', 'operator'], true);
    }

    public function revoke(User $user, License|LicenseActivationKey|null $license = null): bool
    {
        return $this->administer($user);
    }

    public function transfer(User $user): bool
    {
        return $this->administer($user);
    }
}
