<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        $users = User::orderBy('name')->get();
        $roles = Role::orderBy('name')->get();

        return view('admin.roles.index', compact('users', 'roles'));
    }

    public function assign(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role' => 'required|string|exists:roles,name',
        ]);

        $user = User::findOrFail($request->input('user_id'));
        $role = $request->input('role');

        $user->assignRole($role);

        return redirect()->back()->with('status', 'Role assigned.');
    }

    public function remove(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role' => 'required|string|exists:roles,name',
        ]);

        $user = User::findOrFail($request->input('user_id'));
        $role = $request->input('role');

        $user->removeRole($role);

        return redirect()->back()->with('status', 'Role removed.');
    }
}
