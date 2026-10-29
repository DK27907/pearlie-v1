<?php

namespace App\Http\Requests\Admin\Concerns;

trait AuthorizesHospitalSettings
{
    protected function canManageHospitalSettings(): bool
    {
        $user = $this->user();
        $hospital = hospital();

        if ($user === null || $hospital === null || $user->isDoctor()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return (int) $this->session()->get('impersonating_hospital_id') === (int) $hospital->id;
        }

        return $user->isAdmin() && (int) $user->hospital_id === (int) $hospital->id;
    }
}
