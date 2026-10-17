<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    private const MANAGED_ROLES = ['hospital_admin', 'doctor', 'patient'];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(self::MANAGED_ROLES)],
        ]);
        $hospitalId = (int) $request->user()->hospital_id;
        $search = $validated['search'] ?? '';

        $users = User::query()
            ->where('hospital_id', $hospitalId)
            ->where('role', '!=', 'super_admin')
            ->where('is_super_admin', false)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->when(isset($validated['role']), fn (Builder $query): Builder => $query->where('role', $validated['role']))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
            'roleFilter' => $validated['role'] ?? '',
            'roles' => self::MANAGED_ROLES,
        ]);
    }

    public function edit(Request $request, int $user): View
    {
        $record = $this->scopedUser($request, $user);
        Gate::authorize('view', $record);

        return view('admin.users.edit', [
            'user' => $record,
            'roles' => self::MANAGED_ROLES,
        ]);
    }

    public function update(UpdateUserRequest $request, int $user): RedirectResponse
    {
        $record = $this->scopedUser($request, $user);
        Gate::authorize('update', $record);
        $attributes = $request->validated();
        $hospitalId = (int) $request->user()->hospital_id;

        DB::transaction(function () use ($attributes, $hospitalId, $record): void {
            Hospital::query()->whereKey($hospitalId)->lockForUpdate()->firstOrFail();
            $lockedUser = User::query()
                ->where('hospital_id', $hospitalId)
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();
            $role = $attributes['role'];
            $isActive = (bool) $attributes['is_active'];

            if (
                $this->isActiveHospitalAdmin($lockedUser)
                && (! $isActive || $role !== 'hospital_admin')
                && $this->activeHospitalAdminCount($hospitalId) <= 1
            ) {
                abort(422, 'The hospital must retain at least one active hospital administrator.');
            }

            $roleRecord = Role::query()->firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]);
            $lockedUser->forceFill([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => array_key_exists('phone', $attributes) ? $attributes['phone'] : $lockedUser->phone,
                'role' => $role,
                'is_admin' => $role === 'hospital_admin',
                'is_super_admin' => false,
                'is_doctor' => $role === 'doctor',
                'is_active' => $isActive,
                'must_change_password' => $attributes['must_change_password'] ?? $lockedUser->must_change_password,
            ]);

            if (filled($attributes['password'] ?? null)) {
                $lockedUser->password = $attributes['password'];
            }

            $lockedUser->save();
            $lockedUser->syncRoles([$roleRecord]);
        });

        return redirect()->route('admin.users.index')->with('status', 'User details updated.');
    }

    public function destroy(Request $request, int $user): RedirectResponse
    {
        $record = $this->scopedUser($request, $user);
        Gate::authorize('delete', $record);
        $hospitalId = (int) $request->user()->hospital_id;

        DB::transaction(function () use ($hospitalId, $record): void {
            Hospital::query()->whereKey($hospitalId)->lockForUpdate()->firstOrFail();
            $lockedUser = User::query()
                ->where('hospital_id', $hospitalId)
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->isActiveHospitalAdmin($lockedUser) && $this->activeHospitalAdminCount($hospitalId) <= 1) {
                abort(422, 'The hospital must retain at least one active hospital administrator.');
            }

            $lockedUser->forceFill(['is_active' => false])->save();
        });

        return redirect()->route('admin.users.index')->with('status', 'User account deactivated.');
    }

    private function scopedUser(Request $request, int $id): User
    {
        return User::query()
            ->where('hospital_id', $request->user()->hospital_id)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function isActiveHospitalAdmin(User $user): bool
    {
        return $user->is_active
            && ! $user->isSuperAdmin()
            && ($user->is_admin || $user->role === 'hospital_admin' || $user->hasRole(['admin', 'hospital_admin']));
    }

    private function activeHospitalAdminCount(int $hospitalId): int
    {
        return User::query()
            ->where('hospital_id', $hospitalId)
            ->where('is_active', true)
            ->where('is_super_admin', false)
            ->where('role', '!=', 'super_admin')
            ->where(function (Builder $query): void {
                $query->where('role', 'hospital_admin')
                    ->orWhere('is_admin', true)
                    ->orWhereHas('roles', fn (Builder $roles): Builder => $roles->whereIn('name', ['admin', 'hospital_admin']));
            })
            ->count();
    }
}
