<?php

namespace App\Policies;

use App\Models\RouterNode;
use App\Models\User;

class RouterNodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, RouterNode $routerNode): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, RouterNode $routerNode): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, RouterNode $routerNode): bool
    {
        return $user->is_admin;
    }

    public function apply(User $user, RouterNode $routerNode): bool
    {
        return $user->is_admin;
    }

    public function rollback(User $user, RouterNode $routerNode): bool
    {
        return $user->is_admin;
    }
}
