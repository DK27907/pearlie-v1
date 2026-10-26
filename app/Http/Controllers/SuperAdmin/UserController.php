<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    private const MANAGED_ROLES = ['hospital_admin', 'doctor', 'patient'];

    private const ALL_ROLES = ['super_admin', 'hospital_admin', 'doctor', 'patient'];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(self::ALL_ROLES)],
            'hospital_id' => ['nullable', 'integer', Rule::exists('hospitals', 'id')],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $users = User::withoutGlobalScopes()
            ->with('hospital')
            ->when(isset($filters['search']), function (Builder $query) use ($filters): void {
                $search = $filters['search'];
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->when(isset($filters['role']), fn (Builder $query): Builder => $query->where('role', $filters['role']))
            ->when(isset($filters['hospital_id']), fn (Builder $query): Builder => $query->where('hospital_id', $filters['hospital_id']))
            ->when(isset($filters['status']), fn (Builder $query): Builder => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('hospital_id')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $filters['search'] ?? '',
            'roleFilter' => $filters['role'] ?? '',
            'roles' => self::ALL_ROLES,
            'hospitals' => Hospital::query()->orderBy('name')->get(['id', 'name']),
            'hospitalFilter' => $filters['hospital_id'] ?? '',
            'statusFilter' => $filters['status'] ?? '',
            'layout' => 'superadmin.layouts.app',
            'description' => 'Manage accounts and access across all hospitals.',
            'indexRoute' => 'superadmin.users.index',
            'editRoute' => 'superadmin.users.edit',
            'activationRoute' => 'superadmin.users.activation',
            'showHospital' => true,
            'emptyMessage' => 'No users match these filters.',
        ]);
    }

    public function edit(int $user): View
    {
        $record = $this->findManagedUser($user);
        Gate::authorize('view', $record);

        return view('admin.users.edit', [
            'user' => $record,
            'roles' => self::MANAGED_ROLES,
            'layout' => 'superadmin.layouts.app',
            'indexRoute' => 'superadmin.users.index',
            'updateRoute' => 'superadmin.users.update',
            'showHospital' => true,
        ]);
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        $record = $this->findManagedUser($user);
        Gate::authorize('update', $record);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($record->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'role' => $record->isSuperAdmin()
                ? ['prohibited']
                : ['required', Rule::in(self::MANAGED_ROLES)],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', Password::defaults(), 'confirmed'],
            'password_confirmation' => ['required_with:password', 'nullable', 'string'],
            'must_change_password' => ['sometimes', 'boolean'],
        ]);

        $hospitalId = (int) $record->hospital_id;
        $role = $record->isSuperAdmin() ? null : $attributes['role'];
        $isActive = (bool) $attributes['is_active'];

        DB::transaction(function () use ($attributes, $hospitalId, $isActive, $record, $request, $role): void {
            Hospital::query()->whereKey($hospitalId)->lockForUpdate()->firstOrFail();

            if ($record->isSuperAdmin()) {
                $this->lockSuperAdminAccounts();
            }

            $lockedUser = User::withoutGlobalScopes()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActivationAllowed($request->user(), $lockedUser, $isActive, $role);

            $lockedUser->forceFill([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => array_key_exists('phone', $attributes) ? $attributes['phone'] : $lockedUser->phone,
                'is_active' => $isActive,
                'must_change_password' => $attributes['must_change_password'] ?? $lockedUser->must_change_password,
            ]);

            if ($role !== null) {
                $lockedUser->forceFill([
                    'role' => $role,
                    'is_admin' => $role === 'hospital_admin',
                    'is_super_admin' => false,
                    'is_doctor' => $role === 'doctor',
                ]);
            }

            if (filled($attributes['password'] ?? null)) {
                $lockedUser->password = $attributes['password'];
                $lockedUser->must_change_password = true;
                $lockedUser->remember_token = Str::random(60);
            }

            $lockedUser->save();

            if ($role !== null) {
                $lockedUser->syncRolesFromFlags();
            }
        });

        return redirect()->route('superadmin.users.index')->with('status', 'User account updated.');
    }

    public function updateActivation(Request $request, int $user): RedirectResponse
    {
        $record = $this->findManagedUser($user);
        Gate::authorize('update', $record);

        $attributes = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $isActive = (bool) $attributes['is_active'];
        $hospitalId = (int) $record->hospital_id;

        DB::transaction(function () use ($isActive, $hospitalId, $record, $request): void {
            Hospital::query()->whereKey($hospitalId)->lockForUpdate()->firstOrFail();

            if ($record->isSuperAdmin()) {
                $this->lockSuperAdminAccounts();
            }

            $lockedUser = User::withoutGlobalScopes()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActivationAllowed($request->user(), $lockedUser, $isActive, null);
            $lockedUser->forceFill(['is_active' => $isActive])->save();
        });

        return redirect()->route('superadmin.users.index')->with('status', 'User account status updated.');
    }

    private function findManagedUser(int $id): User
    {
        return User::withoutGlobalScopes()
            ->whereNotNull('hospital_id')
            ->whereKey($id)
            ->firstOrFail();
    }

    private function lockSuperAdminAccounts(): void
    {
        User::withoutGlobalScopes()
            ->where(function (Builder $query): void {
                $query->where('is_super_admin', true)
                    ->orWhere('role', 'super_admin');
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    private function assertActivationAllowed(User $actor, User $user, bool $isActive, ?string $role): void
    {
        if ($user->isSuperAdmin()) {
            $activeSuperAdminCount = User::withoutGlobalScopes()
                ->where(function (Builder $query): void {
                    $query->where('is_super_admin', true)
                        ->orWhere('role', 'super_admin');
                })
                ->where('is_active', true)
                ->count();

            if (! $isActive && ($user->id === $actor->id || $activeSuperAdminCount <= 1)) {
                abort(422, 'The platform must retain an active super administrator, and you cannot deactivate your own account.');
            }

            return;
        }

        $isHospitalAdmin = $user->is_active
            && ($user->is_admin || $user->role === 'hospital_admin' || $user->hasRole(['admin', 'hospital_admin']));

        if (
            $isHospitalAdmin
            && (! $isActive || ($role !== null && $role !== 'hospital_admin'))
            && $this->activeHospitalAdminCount((int) $user->hospital_id) <= 1
        ) {
            abort(422, 'The hospital must retain at least one active hospital administrator.');
        }
    }

    private function activeHospitalAdminCount(int $hospitalId): int
    {
        return User::withoutGlobalScopes()
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
