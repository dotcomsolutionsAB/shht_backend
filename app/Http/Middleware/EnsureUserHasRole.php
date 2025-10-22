<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        // If no authenticated user
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // 2) Normalize roles (handle "role:admin,sales" or multiple role: params)
        $allowedRoles = collect($roles)
            ->flatMap(fn ($r) => array_map('trim', explode(',', $r)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        // 3) If no roles specified, just allow
        if (empty($allowedRoles)) {
            return $next($request);
        }

        // 4) Compare user role
        if (! in_array($user->role, $allowedRoles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. Your role (' . $user->role . ') is not allowed for this route.',
            ], 403);
        }

        // 5) Continue
        return $next($request);
    }
}
