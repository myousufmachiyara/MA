<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    /**
     * Read-only list of all permissions in the system — mainly useful
     * for admins auditing what exists, since assigning permissions to
     * roles already happens on the Role create/edit screen.
     */
    public function index()
    {
        $permissions = Permission::orderBy('name')->get();

        $grouped = [];
        foreach ($permissions as $permission) {
            $parts = explode('.', $permission->name);
            $module = $parts[0] ?? 'other';
            $grouped[$module][] = $permission;
        }
        ksort($grouped);

        return view('permissions.index', compact('grouped'));
    }
}