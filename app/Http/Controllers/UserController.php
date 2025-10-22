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
            'username'      => ['required', 'string', 'max:255', 'unique:users,username'],
            'password'      => ['required', 'string', 'min:8'], // add 'confirmed' if you pass password_confirmation
            'email'         => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'role'          => ['required',Rule::in(['admin', 'sales', 'staff', 'dispatch'])],
            'order_views'   => ['required', Rule::in(['self', 'global'])],
            'change_status' => ['required', Rule::in(['0', '1'])],
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
                $u->role          = $validated['role'];
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
                    'role'          => $user->role,
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

    /**
     * Fetch a specific user by ID
     * OR fetch all users (paginated) when no ID is provided
     */
    public function list(Request $request, $id = null)
    {
        try {
            if ($id) {
                // --- Fetch single user by ID ---
                $user = User::select('id', 'name', 'email', 'username', 'order_views', 'change_status')
                    ->find($id);

                if (!$user) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'User not found',
                    ], 404);
                }

                return response()->json([
                    'status'  => true,
                    'message' => 'User fetched successfully',
                    'data'    => $user,
                ], 200);
            }

            // --- Otherwise fetch multiple users ---
            $limit  = $request->input('limit', 10);
            $offset = $request->input('offset', 0);

            $users = User::select('id', 'name', 'email', 'username', 'order_views', 'change_status')
                ->skip($offset)
                ->take($limit)
                ->orderBy('id', 'desc')
                ->get();

            return response()->json([
                'status'  => true,
                'message' => 'Users fetched successfully',
                'count'   => $users->count(),
                'data'    => $users,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Fetch Users Failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while fetching users.',
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'name'          => 'required|string|max:255',
                'email'         => ['nullable','email','max:255',Rule::unique('users','email')->ignore($id)],
                'password'      => 'required|string|min:8',
                'username'      => ['required','string','max:255',Rule::unique('users','username')->ignore($id)],
                'order_views'   => ['required',Rule::in(['self','global'])],
                'change_status' => ['required',Rule::in(['0','1'])],
            ]);

            $updated = User::where('id', $id)->update([
                'name'          => $request->name,
                'email'         => $request->filled('email') ? strtolower($request->email) : null,
                'password'      => bcrypt($request->password),
                'username'      => $request->username,
                'order_views'   => $request->order_views,
                'change_status' => $request->change_status,
            ]);

            $user = User::select('id','name','email','username','order_views','change_status','updated_at')
                        ->find($id);

            return response()->json([
                'code'    => 200,
                'success' => true,
                'message' => $updated ? 'User record updated successfully!' : 'No changes detected.',
                'data'    => $user,
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('User update failed', [
                'user_id' => $id,
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'success' => false,
                'message' => 'Something went wrong while updating user!',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function delete($id)
    {
        try {
            // Check if user exists
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'code'    => 404,
                    'success' => false,
                    'message' => 'User not found!',
                ], 404);
            }

            // Delete record
            $user->delete();

            return response()->json([
                'code'    => 200,
                'success' => true,
                'message' => 'User deleted successfully!',
                'data'    => ['id' => $id, 'name' => $user->name, 'username' => $user->username],
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('User delete failed', [
                'user_id' => $id,
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'success' => false,
                'message' => 'Something went wrong while deleting user!',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
