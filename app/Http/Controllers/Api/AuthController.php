<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'phone'       => 'required|string',
            'password'    => 'required|string',
            'device_id'   => 'nullable|string',
            'app_version' => 'nullable|string',
            'fcm_token'   => 'nullable|string',
        ]);

        $user = User::where('phone', $request->phone)
            ->where('user_type', 'mobile')
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['success' => false, 'message' => 'Invalid phone or password.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['success' => false, 'message' => 'Your account has been deactivated. Contact your office.'], 403);
        }

        // Single active session per booker — revoke any previous token
        $user->tokens()->delete();

        $token = $user->createToken('booker-app')->plainTextToken;

        $user->update([
            'device_id'      => $request->device_id ?? $user->device_id,
            'fcm_token'      => $request->fcm_token ?? $user->fcm_token,
            'app_version'    => $request->app_version ?? $user->app_version,
            'last_login_at'  => now(),
            'last_active_at' => now(),
        ]);

        $user->recordActivity('login', 'Booker logged in', $request);

        Log::info('[Booker] Login', ['user_id' => $user->id, 'device_id' => $request->device_id]);

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => [
                'id'            => $user->id,
                'name'          => $user->name,
                'phone'         => $user->phone,
                'employee_code' => $user->employee_code,
                'assigned_area' => $user->assigned_area,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $user->recordActivity('logout', 'Booker logged out', $request);
        $user->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Logged out successfully.']);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $user->update(['last_active_at' => now()]);

        return response()->json([
            'success' => true,
            'user' => [
                'id'            => $user->id,
                'name'          => $user->name,
                'phone'         => $user->phone,
                'employee_code' => $user->employee_code,
                'assigned_area' => $user->assigned_area,
                'is_active'     => $user->is_active,
            ],
        ]);
    }
}