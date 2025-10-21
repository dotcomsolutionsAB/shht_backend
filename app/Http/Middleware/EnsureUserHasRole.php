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
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // If no authenticated user
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Flatten multiple role params ("role:admin,manager" or "role:admin","role:manager")
        $roles = collect($roles)
            ->flatMap(fn ($r) => array_map('trim', explode(',', $r)))
            ->filter()
            ->values()
            ->all();

        // If no roles specified, allow through
        if (empty($roles)) {
            return $next($request);
        }

        // Check if user role matches any allowed roles
        if (! in_array($user->role, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. Your role (' . $user->role . ') is not allowed for this route.',
            ], 403);
        }
        
        return $next($request);
    }
}
