<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageHospitalUsers($user);
    }

    public function view(User $user, User $model): bool
    {
        return $this->canManage($user, $model);
    }

    public function create(User $user): bool
    {
        return $this->canManageHospitalUsers($user);
    }

    public function update(User $user, User $model): bool
    {
        return $this->canManage($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        return $this->canManage($user, $model);
    }

    private function canManage(User $actor, User $target): bool
    {
        return $this->canManageHospitalUsers($actor)
            && $target->hospital_id !== null
            && (int) $actor->hospital_id === (int) $target->hospital_id
            && ! $target->isSuperAdmin();
    }

    private function canManageHospitalUsers(User $user): bool
    {
        return $user->is_active
            && $user->hospital_id !== null
            && ($user->isSuperAdmin() || ($user->isAdmin() && ! $user->isSuperAdmin()));
    }
}
