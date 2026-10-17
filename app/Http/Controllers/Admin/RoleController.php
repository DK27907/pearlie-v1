<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    private const MANAGED_ROLES = ['hospital_admin', 'doctor', 'patient'];

    public function index(): RedirectResponse
    {
        return redirect()->route('admin.users.index');
    }

    public function assign(Request $request): RedirectResponse
    {
        return $this->redirectToUserEditor($request);
    }

    public function remove(Request $request): RedirectResponse
    {
        return $this->redirectToUserEditor($request);
    }

    private function redirectToUserEditor(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['required', Rule::in(self::MANAGED_ROLES)],
        ]);
        $user = User::query()
            ->where('hospital_id', $request->user()->hospital_id)
            ->whereKey($validated['user_id'])
            ->firstOrFail();
        Gate::authorize('update', $user);

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', 'Update the role in the user account form and save your changes.');
    }
}
