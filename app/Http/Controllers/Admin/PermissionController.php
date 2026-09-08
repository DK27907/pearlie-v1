<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionController extends Controller
{
    public function index()
    {
        $permissions = Permission::orderBy('name')->get();
        $roles = Role::orderBy('name')->get();

        return view('admin.permissions.index', compact('permissions', 'roles'));
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:permissions,name']);
        Permission::create(['name' => $request->input('name')]);
        return redirect()->back()->with('status', 'Permission created.');
    }

    public function destroy(string $name)
    {
        $perm = Permission::where('name', $name)->firstOrFail();
        $perm->delete();
        return redirect()->back()->with('status', 'Permission deleted.');
    }

    public function assign(Request $request)
    {
        $request->validate([
            'role' => 'required|string|exists:roles,name',
            'permission' => 'required|string|exists:permissions,name',
        ]);

        $role = Role::where('name', $request->input('role'))->first();
        $role->givePermissionTo($request->input('permission'));

        return redirect()->back()->with('status', 'Permission assigned to role.');
    }

    public function revoke(Request $request)
    {
        $request->validate([
            'role' => 'required|string|exists:roles,name',
            'permission' => 'required|string|exists:permissions,name',
        ]);

        $role = Role::where('name', $request->input('role'))->first();
        $role->revokePermissionTo($request->input('permission'));

        return redirect()->back()->with('status', 'Permission revoked from role.');
    }
}
