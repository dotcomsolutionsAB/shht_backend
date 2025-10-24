<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    //
    // user `login`
    public function login(Request $request)
    {
        try {
            $request->validate([
                'username' => 'required|string',
                'password' => 'required|string',
            ]);

            // Attempt login using the username column
            if (! Auth::attempt(['username' => $request->username, 'password' => $request->password])) {
                return response()->json([
                    'code'    => 401,
                    'success' => false,
                    'message' => 'Invalid Username or Password.',
                ], 401);
            }

            $user  = Auth::user();
            $token = $user->createToken('API TOKEN')->plainTextToken; // requires Sanctum

            return response()->json([
                'success' => true,
                'message' => 'User logged in successfully!',
                'data' => [
                    'code'        => 200,
                    'token'       => $token,
                    'user_id'     => $user->id,
                    'name'        => $user->name,
                    'username'    => $user->username,
                    'email'       => $user->email,
                    'order_views' => $user->order_views,
                    'change_status'=> $user->change_status,
                ],
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('Login failed', [
                'user'  => $request->input('username'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'code'    => 500,
                'success' => false,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // user `logout`
    public function logout(Request $request)
    {
        try {
            // Ensure an authenticated user exists
            if (! $request->user()) {
                return response()->json([
                    'code'    => 401,
                    'success' => false,
                    'message' => 'Sorry, no user is logged in now!',
                ], 401);
            }

            // Delete only the current access token (Sanctum)
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'code'    => 200,
                'success' => true,
                'message' => 'Logged out successfully!',
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('Logout failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'success' => false,
                'message' => 'Something went wrong during logout!',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
