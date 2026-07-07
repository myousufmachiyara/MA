<?php

namespace App\Http\Middleware;

use Closure;

class EnsureUserActive
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();

        if ($user && !$user->is_active) {
            // Force logout everywhere — revoke every token immediately
            $user->tokens()->delete();

            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact your office.',
            ], 403);
        }

        return $next($request);
    }
}