<?php

namespace App\Http\Controllers;
use App\Models\User;
use App\Models\OrdersModel;
use App\Models\ClientsModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Illuminate\Http\Request;

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
            'mobile'        => ['required', 'string', 'max:15'],
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
                $u->mobile        = $validated['mobile'];
                $u->order_views   = $validated['order_views']   ?? 'self';
                $u->change_status = $validated['change_status'] ?? '0';
                $u->email_status    = '0';
                $u->whatsapp_status = '0';

                $u->save();

                return $u;
            });

            // 3) Return a safe payload (never return password)
            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'User created successfully.',
                'data'    => [
                    'id'            => $user->id,
                    'name'          => $user->name,
                    'username'      => $user->username,
                    'email'         => $user->email,
                    'role'          => $user->role,
                    'mobile'        => $user->mobile,
                    'order_views'   => $user->order_views,
                    'change_status' => $user->change_status,
                    'email_status'    => $user->email_status,
                    'whatsapp_status' => $user->whatsapp_status,
                    'created_at'    => $user->created_at,
                ],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('User create failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Failed to create user.',
            ], 500);
        }
    }

    /**
     * Fetch a specific user by ID
     * OR fetch all users (paginated) when no ID is provided
     */
    public function fetch(Request $request, $id = null)
    {
        try {
            if ($id) {
                // --- Fetch single user by ID ---
                $user = User::select('id', 'name', 'email', 'username', 'role', 'order_views', 'change_status', 'email_status', 'whatsapp_status')
                    ->find($id);

                if (!$user) {
                    return response()->json([
                        'code'    => 404,
                        'status'  => false,
                        'message' => 'User not found',
                    ], 404);
                }

                return response()->json([
                    'code'    => 200,
                    'status'  => true,
                    'message' => 'User fetched successfully',
                    'data'    => $user,
                ], 200);
            }

            // --- List all users with pagination + search ---
            $limit  = (int) $request->input('limit', 10);
            $offset = (int) $request->input('offset', 0);
            $search = trim((string) $request->input('search', ''));

            // NEW Filter Inputs
            $filterEmailStatus    = $request->input('email_status');    // yes/no
            $filterWhatsappStatus = $request->input('whatsapp_status'); // yes/no
            $filterRole = $request->input('role'); // admin, sales, staff, dispatch

            // Total before filtering
            $total = User::count();

            // Query for filtered data
            $q = User::select('id','name','email','username','role','category','mobile','order_views','change_status','email_status','whatsapp_status')
                ->orderBy('id','desc');

            if ($search !== '') {
                $q->where(function ($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
                });
            }

            // NEW Filters
            if (!empty($filterEmailStatus) && in_array($filterEmailStatus, ['yes', 'no'])) {
                $q->where('email_status', $filterEmailStatus);
            }

            if (!empty($filterWhatsappStatus) && in_array($filterWhatsappStatus, ['yes', 'no'])) {
                $q->where('whatsapp_status', $filterWhatsappStatus);
            }

            if (!empty($filterRole) && in_array($filterRole, ['admin', 'sales', 'staff', 'dispatch'])) {
                $q->where('role', $filterRole);
            }

            $users = $q->skip($offset)->take($limit)->get();

            // Count after filter
            $count = $users->count();

            // Final response
            return response()->json([
                'code'    => 200,
                'status'  => 'success',
                'message' => 'Users retrieved successfully.',
                'total'   => $total,
                'count'   => $count,
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

    // Edit
    public function edit(Request $request, $id)
    {
        try {
            $request->validate([
                'name'          => 'required|string|max:255',
                'email'         => ['nullable','email','max:255',Rule::unique('users','email')->ignore($id)],
                // 'password'      => 'required|string|min:8',
                'username'      => ['required','string','max:255',Rule::unique('users','username')->ignore($id)],
                'mobile'        => ['required', 'string', 'max:15'],
                'order_views'   => ['required',Rule::in(['self','global'])],
                'change_status' => ['required',Rule::in(['0','1'])],
                'whatsapp_status' => ['nullable', Rule::in(['0','1'])],
                'email_status'    => ['nullable', Rule::in(['0','1'])],
            ]);

            $updated = User::where('id', $id)->update([
                'name'          => $request->name,
                'email'         => $request->filled('email') ? strtolower($request->email) : null,
                // 'password'      => bcrypt($request->password),
                'username'      => $request->username,
                'mobile'        => $request->mobile,
                'order_views'   => $request->order_views,
                'change_status' => $request->change_status,
                'email_status' => $request->email_status,
                'whatsapp_status' => $request->whatsapp_status,
            ]);

            $user = User::select('id','name','email','username','mobile','order_views','change_status','updated_at', 'email_status', 'whatsapp_status')
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

    // Delete
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

    // export
    public function exportExcel(Request $request)
    {
        try {
            /* ---------- 1.  same search filter as fetch() ---------- */
            $search = trim((string) $request->input('search', ''));

            $q = User::select('id','name','email','username','mobile','order_views','change_status')
                ->orderBy('id','desc');

            if ($search !== '') {
                $q->where(function ($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
                });
            }

            $rows = $q->get();   // everything that matches filters

            /* ---------- 2.  Excel ---------- */
            $spreadsheet = new Spreadsheet();
            $sheet       = $spreadsheet->getActiveSheet();

            // headers
            $headers = [
                'Sl. No.',
                'Users',
                'Role',
                'Mobile',
                'View',
                'View Global',
            ];
            $sheet->fromArray($headers, null, 'A1');

            // ✅ Center align headers + bold + border + background
            $sheet->getStyle('A1:F1')->applyFromArray([
                'font' => [
                    'bold' => true,
                    'size' => 11,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['argb' => '000000'],
                    ],
                ],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFEFEFEF'],
                ],
            ]);

            // Optional: increase row height so header looks balanced
            $sheet->getRowDimension(1)->setRowHeight(22);

            // Keep your existing column D as text format
            $sheet->getStyle('D')->getNumberFormat()->setFormatCode('@');

            $rowNo = 2;
            $sl    = 1;
            foreach ($rows as $u) {
                // You can still use fromArray for convenience
                $sheet->fromArray([
                    $sl,
                    $u->name,             // Users
                    'User',               // Role
                    '',                   // placeholder for Mobile; set explicitly next line
                    $u->order_views ? 'Yes' : 'No',
                    $u->change_status ? 'Yes' : 'No',
                ], null, "A{$rowNo}");

                // Write Mobile explicitly as a STRING (prevents scientific notation)
                $sheet->setCellValueExplicit("D{$rowNo}", (string) $u->mobile, DataType::TYPE_STRING);

                // borders
                $sheet->getStyle("A{$rowNo}:F{$rowNo}")->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['argb' => '000000'],
                        ],
                    ],
                ]);

                $rowNo++;
                $sl++;
            }
            foreach (range('A','F') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            /* ---------- 3.  save to disk & return URL ---------- */
            $filename  = 'users_export_' . now()->format('Ymd_His') . '.xlsx';
            $directory = 'users';                      // storage/app/public/users/

            if (!Storage::disk('public')->exists($directory)) {
                Storage::disk('public')->makeDirectory($directory);
            }

            $path = storage_path("app/public/{$directory}/{$filename}");
            $writer = new Xlsx($spreadsheet);
            $writer->save($path);

            $publicUrl = Storage::disk('public')->url("{$directory}/{$filename}");

            return response()->json([
                'code'    => 200,
                'success' => true,
                'message' => 'Users exported successfully.',
                'data'    => [
                    'file_url' => $publicUrl,
                    'count'    => $rows->count(),
                ],
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('Users Excel export failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'success'  => false,
                'message' => 'Something went wrong while exporting Excel.',
                'data'    => [],
            ], 500);
        }
    }

    // dashboard
    public function summary()
    {
        try {
            // Aggregate order counts in one query
            $ordersAgg = OrdersModel::selectRaw("
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as total_pending_orders,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as total_completed_orders,
                SUM(CASE WHEN status = 'short_closed' THEN 1 ELSE 0 END) as total_short_closed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as total_cancelled
            ")->first();

            // Other totals
            $totalClients = ClientsModel::count();
            $totalUsers   = User::count();

            return response()->json([
                'code'    => 200,
                'status'  => 'success',
                'message' => 'Dashboard metrics retrieved.',
                'data'    => [
                    'total_orders'           => (int) ($ordersAgg->total_orders ?? 0),
                    'total_clients'          => (int) $totalClients,
                    'total_users'            => (int) $totalUsers,
                    'total_pending_orders'   => (int) ($ordersAgg->total_pending_orders ?? 0),
                    'total_completed_orders' => (int) ($ordersAgg->total_completed_orders ?? 0),
                    'total_short_closed'     => (int) ($ordersAgg->total_short_closed ?? 0),
                    'total_cancelled'        => (int) ($ordersAgg->total_cancelled ?? 0),
                ],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Dashboard summary failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'status'  => 'error',
                'message' => 'Something went wrong while fetching dashboard metrics.',
            ], 500);
        }
    }

    // update password
    /**
     * Update a user's password by user_id.
     * Expects: user_id, password, password_confirmation
     */
    public function updatePassword(Request $request)
    {
        try {
            // 1. Validate request
            $validated = $request->validate([
                'user_id'  => ['required', 'integer'],
                'password' => ['required', 'string', 'min:8', 'confirmed'], // password_confirmation is required automatically
            ]);

            // 2. Check if user exists (no deleted_at used)
            $user = User::where('id', $validated['user_id'])->first();

            if (!$user) {
                return response()->json([
                    'code'    => 404,
                    'success' => false,
                    'message' => 'User not found — invalid user_id.',
                ], 404);
            }

            // 3. Update password
            $user->password = Hash::make($validated['password']);
            $user->save();

            return response()->json([
                'code'    => 200,
                'success' => true,
                'message' => 'Password updated successfully.',
                'data'    => ['user_name' => $user->username],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Update password failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'success' => false,
                'message' => 'Something went wrong while updating password.',
                'data'    => [],
            ], 500);
        }
    }
}
