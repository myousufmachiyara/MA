<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class MobileUserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('user_type', 'mobile');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        $bookers = $query->latest()->get();

        return view('mobile_users.index', compact('bookers'));
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'          => 'required|string|max:255',
                'phone'         => 'required|string|unique:users,phone',
                'username'      => 'nullable|string|unique:users,username',
                'password'      => 'required|string|min:6|confirmed',
                'employee_code' => 'nullable|string|max:50',
                'assigned_area' => 'nullable|string|max:150',
                'cnic'          => 'nullable|string|max:20',
            ]);

            $user = User::create([
                'name'          => $validated['name'],
                'phone'         => $validated['phone'],
                'username'      => $validated['username'] ?: $validated['phone'],
                'password'      => Hash::make($validated['password']),
                'employee_code' => $validated['employee_code'] ?? null,
                'assigned_area' => $validated['assigned_area'] ?? null,
                'cnic'          => $validated['cnic'] ?? null,
                'user_type'     => 'mobile',
                'is_active'     => true,
                'created_by'    => auth()->id(),
                'updated_by'    => auth()->id(),
            ]);

            Log::info('[MobileUser] Booker created', ['id' => $user->id, 'by' => auth()->id()]);

            return redirect()->route('mobile_users.index')->with('success', 'Order booker created successfully.');

        } catch (\Exception $e) {
            Log::error('[MobileUser] Store error', ['message' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $user = User::where('user_type', 'mobile')->findOrFail($id);

        return response()->json([
            'status' => true,
            'data'   => $user->only(['id', 'name', 'phone', 'username', 'employee_code', 'assigned_area', 'cnic', 'is_active']),
        ]);
    }

    public function update(Request $request, $id)
    {
        try {
            $user = User::where('user_type', 'mobile')->findOrFail($id);

            $validated = $request->validate([
                'name'          => 'required|string|max:255',
                'phone'         => ['required', 'string', Rule::unique('users', 'phone')->ignore($user->id)],
                'employee_code' => 'nullable|string|max:50',
                'assigned_area' => 'nullable|string|max:150',
                'cnic'          => 'nullable|string|max:20',
            ]);

            $user->update(array_merge($validated, ['updated_by' => auth()->id()]));

            Log::info('[MobileUser] Booker updated', ['id' => $id, 'by' => auth()->id()]);

            return redirect()->route('mobile_users.index')->with('success', 'Booker updated successfully.');

        } catch (\Exception $e) {
            Log::error('[MobileUser] Update error', ['message' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    public function toggleActive($id)
    {
        $user = User::where('user_type', 'mobile')->findOrFail($id);
        $user->is_active = !$user->is_active;
        $user->save();

        // Immediately kick them off the app if deactivated
        if (!$user->is_active) {
            $user->tokens()->delete();
        }

        $status = $user->is_active ? 'activated' : 'deactivated';
        Log::info("[MobileUser] Booker {$status}", ['id' => $id, 'by' => auth()->id()]);

        return back()->with('success', "Booker {$status} successfully.");
    }

    /**
     * Unbind the booker's device — e.g. lost phone, replaced device.
     * Lets them log in fresh on a new device without deactivating the account.
     */
    public function resetDevice($id)
    {
        $user = User::where('user_type', 'mobile')->findOrFail($id);
        $user->update(['device_id' => null]);
        $user->tokens()->delete();

        return back()->with('success', 'Device unlinked. Booker can log in on a new device now.');
    }

    public function activity($id)
    {
        $user = User::where('user_type', 'mobile')->findOrFail($id);
        $logs = UserActivityLog::where('user_id', $id)->latest()->paginate(50);

        return view('mobile_users.activity', compact('user', 'logs'));
    }

    public function destroy($id)
    {
        $user = User::where('user_type', 'mobile')->findOrFail($id);
        $user->tokens()->delete();
        $user->delete();

        return back()->with('success', 'Booker deleted successfully.');
    }
}