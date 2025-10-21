<?php

namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    //
        /**
     * Create a new user (login via username + password).
     */
    public function create(Request $request)
    {
        // 1) Validate input
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'username'      => ['required', 'string', 'max:255', 'alpha_dash', 'unique:users,username'],
            'password'      => ['required', 'string', 'min:8'], // add 'confirmed' if you pass password_confirmation
            'email'         => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'order_views'   => ['nullable', Rule::in(['self', 'global'])],
            'change_status' => ['nullable', Rule::in(['0', '1'])],
        ]);

        // 2) Create user inside a transaction
        try {
            $user = DB::transaction(function () use ($validated) {
                $u = new User();

                // Column-wise assignment (no mass assignment)
                $u->name          = $validated['name'];
                $u->username      = $validated['username'];
                $u->password      = Hash::make($validated['password']);
                $u->email         = $validated['email'] ?? null;
                $u->order_views   = $validated['order_views']   ?? 'self';
                $u->change_status = $validated['change_status'] ?? '0';

                $u->save();

                return $u;
            });

            // 3) Return a safe payload (never return password)
            return response()->json([
                'status'  => true,
                'message' => 'User created successfully.',
                'data'    => [
                    'id'            => $user->id,
                    'name'          => $user->name,
                    'username'      => $user->username,
                    'email'         => $user->email,
                    'order_views'   => $user->order_views,
                    'change_status' => $user->change_status,
                    'created_at'    => $user->created_at,
                ],
            ], 201);

        } catch (\Throwable $e) {
            Log::error('User create failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Failed to create user.',
            ], 500);
        }
    }
}
