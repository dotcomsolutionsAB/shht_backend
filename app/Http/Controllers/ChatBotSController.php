<?php

namespace App\Http\Controllers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\ClientsModel;
use App\Models\OrdersModel;
use App\Models\User;
use App\Models\InvoiceModel;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class ChatBotSController extends Controller
{
    public function getClientOrders(Request $request): JsonResponse
    {
        // 1) Validate inputs
        $validator = Validator::make($request->all(), [
            'client' => ['nullable', 'string', 'max:255'],
            'page'   => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 422,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $clientStr = trim((string) $request->input('client', ''));  // search by client name (LIKE)
        $page      = (int) ($request->input('page', 1) ?: 1);       // default page = 1
        $perPage   = 5;

        // 👉 If no client string passed, treat as "no result" (search only)
        if ($clientStr === '') {
            return response()->json([
                'status'   => 200,
                'page'     => $page,
                'has_more' => false,
                'content'  => '',
                'json'     => [],
            ], 200);
        }

        // 2) Build query using clientRef relation
        // $query = OrdersModel::with('clientRef')
        //     ->whereHas('clientRef', function ($q) use ($clientStr) {
        //         $q->where('name', 'like', '%' . $clientStr . '%');
        //     });

        // 2) Build query using clientRef relation + status filter
        $query = OrdersModel::with('clientRef')
            ->whereIn('status', ['pending','partial_pending','completed'])
            ->whereHas('clientRef', function ($q) use ($clientStr) {
                $q->where('name', 'like', '%' . $clientStr . '%');
            });

        // 3) Total count for pagination
        $total = $query->count();

        // 👉 If no records matched, return empty but status 200
        if ($total === 0) {
            return response()->json([
                'status'   => 200,
                'page'     => $page,
                'has_more' => false,
                'content'  => '',
                'json'     => [],
            ], 200);
        }

        // 4) Fetch paginated records (newest first; adjust orderBy as per your need)
        $offset = ($page - 1) * $perPage;

        $orders = $query
            ->orderBy('so_date', 'desc')   // or 'id' / 'order_date' depending on your business rule
            ->skip($offset)
            ->take($perPage)
            ->get();

        // 5) Build content string + json array
        $lines = [];
        $json  = [""];   // 👈 start as empty array, not [""]

        $sn    = 1;

        foreach ($orders as $order) {
            $clientName = $order->clientRef->name ?? '';

            // Use so_no + so_date as per your example
            $soNo = $order->so_no ?? '';
            $date = $order->so_date
                ? \Carbon\Carbon::parse($order->so_date)->format('d-m-Y')
                : '';

            // Adjust this to your actual amount column (e.g. total, order_value, grand_total)
            $orderValue = $order->order_value ?? 0;   // <-- change if needed

            $line = sprintf(
                "
SN: %d\nClient: *%s*\nOrder No: %s\nOrder Date: %s\nOrder Value: %.2f\n\n",
                $sn,
                $clientName,
                $soNo,
                $date,
                $orderValue
            );

            $lines[] = $line;
            $json[]  = $soNo;  // push actual SO number only

            $sn++;
        }

        // If multiple records → join with *two* spaces
        $content = implode('  ', $lines);

        $hasMore = $total > ($page * $perPage);

        return response()->json([
            'status'   => 200,
            'page'     => $page,
            'has_more' => $hasMore,
            'content'  => $content,
            'json'     => $json,
        ], 200);
    }

    public function checkMobile(Request $request)
    {
        // 1️⃣ Validate basic input (string, not digits:12 anymore)
        $validator = Validator::make($request->all(), [
            'mobile' => ['required', 'string', 'min:10'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 422,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // 2️⃣ Normalize input mobile → last 10 digits
        $inputDigits = preg_replace('/\D+/', '', $request->input('mobile'));
        $mobile10    = substr($inputDigits, -10);

        if (strlen($mobile10) !== 10) {
            return response()->json([
                'status'  => 422,
                'message' => 'Invalid Indian mobile number.',
                'exists'  => false,
            ], 422);
        }

        // 3️⃣ Find user by matching last 10 digits of DB mobile
        // ✅ MySQL 8+ compatible
        $user = User::whereRaw(
            "RIGHT(REGEXP_REPLACE(mobile, '[^0-9]', ''), 10) = ?",
            [$mobile10]
        )->first();

        // 4️⃣ Not found
        if (!$user) {
            return response()->json([
                'status' => 200,
                'exists' => false,
                'role'   => null,
                'id'     => null,
            ], 200);
        }

        // 5️⃣ Found
        return response()->json([
            'status' => 200,
            'exists' => true,
            'role'   => $user->role ?? null,
            'category'   => $user->category ?? null,
            'id'     => $user->id ?? null,
        ], 200);
    }

    public function getDispatchUsers(): JsonResponse
    {
        // Fetch dispatch users + count of "current" assigned orders
        $dispatchUsers = User::where('role', 'dispatch')
            ->withCount([
                'dispatchOrders as current_orders_count' => function ($q) {
                    // ---- DEFINE "current" HERE (edit to match your statuses) ----
                    $q->whereNotIn('status', ['completed', 'cancelled', 'refunded']);
                    // If you track delivery_status separately, you can also add:
                    // $q->whereNotIn('delivery_status', ['completed', 'cancelled']);
                }
            ])
            ->orderBy('name', 'asc')
            ->get();

        $lines  = [];
        $json   = [""];   // first element always blank
        $name   = [""];   // first element always blank
        $mobile = [""];   // first element always blank
        $count  = [""];   // first element always blank
        $sn     = 1;

        foreach ($dispatchUsers as $user) {
            $c = (int) ($user->current_orders_count ?? 0);

            // ✅ append count after name
            $lines[]  = sprintf('%d. %s (%d)', $sn, $user->name, $c);

            $json[]   = $user->id;
            $name[]   = $user->name;        // keep pure name (or change to "{$user->name} ({$c})" if you want)
            $mobile[] = '+' . $user->mobile;
            $count[]  = $c;

            $sn++;
        }

        $content = implode("\n", $lines);

        return response()->json([
            'status'  => 200,
            'content' => $content,
            'json'    => $json,
            'name'    => $name,
            'mobile'  => $mobile,
            'count'   => $count, // extra aligned array (optional but useful)
        ], 200);
    }

    public function getOrdersByMobile(Request $request): JsonResponse
    {
        $mobile = trim((string) $request->input('mobile', ''));

        if ($mobile === '') {
            return response()->json([
                'status'  => 422,
                'message' => 'Mobile is required.',
            ], 422);
        }

        // ✅ Normalize input mobile -> last 10 digits (India)
        $digits   = preg_replace('/\D+/', '', $mobile);
        $mobile10 = substr($digits, -10);

        if (strlen($mobile10) !== 10) {
            return response()->json([
                'status'  => 422,
                'message' => 'Invalid Indian mobile number.',
            ], 422);
        }

        // 1) Fetch client_id from mobile (India compatible)
        // NOTE: REGEXP_REPLACE requires MySQL 8+. If you're on MySQL 5.7/MariaDB, tell me.
        $client = User::whereRaw(
                "RIGHT(REGEXP_REPLACE(mobile, '[^0-9]', ''), 10) = ?",
                [$mobile10]
            )
            ->select('id')
            ->first();

        if (!$client) {
            return response()->json([
                'status'  => 404,
                'message' => "No client found for mobile {$mobile}",
            ], 404);
        }

        $client_id = $client->id;

        // 2) Fetch orders where dispatched_by = this client_id
        $orders = OrdersModel::with('clientRef')
            ->where('dispatched_by', $client_id)
            ->where('status', 'dispatched')
            ->orderBy('so_date', 'desc')
            ->get();

        // 3) If no orders found
        if ($orders->isEmpty()) {
            return response()->json([
                'status'  => 404,
                'message' => "No orders found for dispatched_by {$client_id}",
            ], 404);
        }

        // 4) Build content string + json array (same style)
        $lines = [];
        $json  = [""]; // keep your existing style
        $sn    = 1;

        foreach ($orders as $order) {
            $clientName = $order->clientRef->name ?? '';
            $soNo       = $order->so_no ?? '';

            $date = $order->so_date
                ? Carbon::parse($order->so_date)->format('d-m-Y')
                : '';

            $orderValue = (float) ($order->order_value ?? 0);

            $line = sprintf(
                "SN: %d\nClient: %s\nOrder No: %s\nOrder Date: %s\nOrder Value: %.2f\n\n",
                $sn,
                $clientName,
                $soNo,
                $date,
                $orderValue
            );

            $lines[] = $line;
            $json[]  = $soNo;
            $sn++;
        }

        $content = implode('  ', $lines);

        return response()->json([
            'status'  => 200,
            'content' => $content,
            'json'    => $json,
        ], 200);
    }

    public function getOrderDetails(Request $request): JsonResponse
    {
        // Validate the input (order_no)
        $validator = Validator::make($request->all(), [
            'so_number' => 'required|string|exists:t_orders,so_no',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 422,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $soNo = trim($request->input('so_number')); // SO-Number to search

        // Find the order based on the order_no with all related models
        $order = OrdersModel::with([
            'clientRef',           // Client information
            'contactRef.rmUser',   // Contact person with the related RM user
            'initiatedByRef',      // User who initiated the order
            'checkedByRef',        // User who checked the order
            'dispatchedByRef',     // User who dispatched the order
            'invoiceRef',          // Invoice details
        ])->where('so_no', $soNo)
        ->first();

        if (!$order) {
            return response()->json([
                'status'  => 404,
                'message' => 'Order not found.',
                'data'    => [],
            ], 404);
        }

        // Get client details
        $client = $order->clientRef;
        $contactPerson = $order->contactRef;
        $initiatedBy = $order->initiatedByRef;
        $checkedBy = $order->checkedByRef;
        $dispatchedBy = $order->dispatchedByRef;
        $invoice = $order->invoiceRef;

        // Prepare the response data
        $orderDetails = [
            'client' => [
                'id'   => $client->id ?? '',
                'name' => $client->name ?? '',
            ],
            'client_contact_person' => [
                'id'   => $contactPerson->id ?? '',
                'name' => $contactPerson->name ?? '',
                'rm' => [
                    'name'  => $contactPerson->rmUser->name ?? '',
                    'email' => $contactPerson->rmUser->email ?? '',
                    'mobile' => $contactPerson->rmUser->mobile ?? '',
                ],
            ],
            'initiated_by' => [
                'id'   => $initiatedBy->id ?? '',
                'name' => $initiatedBy->name ?? '',
                'email' => $initiatedBy->email ?? '',
                'mobile' => $initiatedBy->mobile ?? '',
            ],
            'checked_by' => [
                'id'   => $checkedBy->id ?? '',
                'name' => $checkedBy->name ?? '',
                'email' => $initiatedBy->email ?? '',
                'mobile' => $initiatedBy->mobile ?? '',
            ],
            'dispatched_by' => [
                'id'   => $dispatchedBy->id ?? '',
                'name' => $dispatchedBy->name ?? '',
                'email' => $initiatedBy->email ?? '',
                'mobile' => $initiatedBy->mobile ?? '',
            ],
            'order_value' => $order->order_value ?? '',
            'invoice' => $invoice ? $invoice : null, // Whole invoice object
            'so_number' => $order->so_no ?? '',
            'so_date'   => $order->so_date ? \Carbon\Carbon::parse($order->so_date)->format('d-m-Y') : '',
            'order_no'  => $order->order_no ?? '',
            'order_date'=> $order->order_date ? \Carbon\Carbon::parse($order->order_date)->format('d-m-Y') : '',
            'status'    => $order->status ?? '',
            'folder_link' => $order->folder_link ?? '',  // Assuming this field exists in the OrdersModel
        ];

        return response()->json([
            'status'  => 200,
            'message' => 'Order details fetched successfully.',
            'data'    => $orderDetails,
        ], 200);
    }

    public function updateOrderStatus(Request $request)
    {
        // 1) Custom Validation Rule for dispatched_by to check if the user has 'dispatch' role
        Validator::extend('role_dispatch', function ($attribute, $value, $parameters, $validator) {
            $user = User::find($value);
            return $user && $user->role === 'dispatch';
        });

        // 2) Validate input data
        $validator = Validator::make($request->all(), [
            'so_number'        => 'required|string|exists:t_orders,so_no',
            'status'           => 'required|in:pending,dispatched,partial_pending,invoiced,completed,short_closed,cancelled,out_of_stock',
            'folder_link'      => 'nullable|url|max:255',
            'dispatched_by'    => 'nullable|exists:users,id',
            'dispatch_remarks' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 422,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // 3) Retrieve inputs
        $orderNo          = $request->input('so_number');
        $status           = $request->input('status');
        $folderLink       = $request->input('folder_link');
        $dispatchRemarks  = $request->input('dispatch_remarks');

        // 4) Find the order
        $order = OrdersModel::where('so_no', $orderNo)->first();

        if (!$order) {
            return response()->json([
                'status'  => 404,
                'message' => 'Order not found.',
            ], 404);
        }

        try {
            $previousStatus = $order->status;
            $dispatchAssignee = null;

            // 5) Prepare update data
            $updateData = [
                'status'           => $status,
                'drive_link'       => $folderLink,
                'dispatch_remarks' => $dispatchRemarks,
            ];

            // ✅ Set dispatched_date as current date when status is dispatched
            if ($status === 'dispatched') {
                $updateData['dispatched_date'] = Carbon::now()->format('Y-m-d');
                $updateData['dispatched_by'] = $request->input('dispatched_by');
                if (!empty($updateData['dispatched_by'])) {
                    $dispatchAssignee = User::find($updateData['dispatched_by']);
                }
            }

            // 6) Update order
            $order->update($updateData);

            if ($previousStatus === 'pending' && $status === 'dispatched' && $dispatchAssignee) {
                try {
                    $clientName = ClientsModel::where('id', $order->client)->value('name');
                    app(WhatsAppService::class)->sendTemplateMessage(
                        $dispatchAssignee->mobile ?? null,
                        'new_shht_dispatch_assigned',
                        [
                            $clientName ?? '',
                            $order->so_no ?? '',
                            $order->order_no ?? '',
                            $order->order_value ?? '',
                            $dispatchAssignee->name ?? '',
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::error('WhatsApp dispatch notification failed (chatbot).', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 🔹 Get initiated_by user's mobile
            $initiatedMobile = null;

            if (!empty($order->initiated_by)) {
                $initiatedUser = User::find($order->initiated_by);

                if ($initiatedUser && !empty($initiatedUser->mobile)) {
                    $initiatedMobile = '+' . ltrim((string) $initiatedUser->mobile, '+');
                }
            }

            // 7) Success response
            return response()->json([
                'status'  => 200,
                'message' => 'Order status updated successfully.',
                'data'    => [
                    'success'          => true,
                    'initiated_mobile' => $initiatedMobile,
                ],
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('Failed to update order status', ['exception' => $e]);

            return response()->json([
                'status'  => 500,
                'message' => 'Failed to update order status.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function markOrderInvoiced(Request $request)
    {
        // ✅ Validate
        $validator = Validator::make($request->all(), [
            'so_number'     => ['required', 'string', 'exists:t_orders,so_no'],
            'invoice_no'    => ['required', 'string', 'max:100'],
            'invoice_date'  => ['required', 'date'],
            'mobile_number' => ['required', 'string', 'max:25'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 422,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $soNo        = trim((string) $request->input('so_number'));
        $invoiceNo   = trim((string) $request->input('invoice_no'));
        $invoiceDate = $request->input('invoice_date');
        $mobile      = trim((string) $request->input('mobile_number'));

        // ✅ Find order
        $order = OrdersModel::where('so_no', $soNo)->first();
        if (!$order) {
            return response()->json([
                'status'  => 404,
                'message' => 'Order not found.',
            ], 404);
        }

        // ✅ Normalize input mobile to last 10 digits (handles +91, 91, spaces, etc.)
        $inputDigits = preg_replace('/\D+/', '', $mobile);
        $input10     = substr($inputDigits, -10);

        if (strlen($input10) !== 10) {
            return response()->json([
                'status'  => 422,
                'message' => 'Invalid mobile number. Please provide a valid 10-digit mobile (with or without +91).',
                'data'    => [],
            ], 422);
        }

        // ✅ Find billing user by matching last 10 digits of DB mobile
        // NOTE: REGEXP_REPLACE requires MySQL 8+. If you are on MySQL 5.7, tell me and I'll give that version.
        $billingUser = User::whereRaw(
            "RIGHT(REGEXP_REPLACE(mobile, '[^0-9]', ''), 10) = ?",
            [$input10]
        )->first();

        if (!$billingUser) {
            return response()->json([
                'status'  => 404,
                'message' => 'Billing user not found for given mobile number.',
                'data'    => [],
            ], 404);
        }

        try {
            $result = DB::transaction(function () use ($order, $invoiceNo, $invoiceDate, $billingUser) {

                // ✅ Create invoice
                $invoice = InvoiceModel::create([
                    'order'          => $order->id,
                    'invoice_number' => $invoiceNo,
                    'invoice_date'   => Carbon::parse($invoiceDate)->format('Y-m-d'),
                    'billed_by'      => $billingUser->id,
                ]);

                // ✅ Update order: status + invoice link
                $order->update([
                    'status'  => 'invoiced',
                    'invoice' => $invoice->id,
                ]);

                // ✅ initiated_by mobile (required in response)
                $initiatedMobile = null;
                if (!empty($order->initiated_by)) {
                    $initiatedUser = User::find($order->initiated_by);
                    if ($initiatedUser && !empty($initiatedUser->mobile)) {
                        $digits10 = substr(preg_replace('/\D+/', '', (string) $initiatedUser->mobile), -10);
                        if (strlen($digits10) === 10) {
                            $initiatedMobile = '+91' . $digits10; // consistent format
                        }
                    }
                }

                return [
                    'invoice_id'       => $invoice->id,
                    'initiated_mobile' => $initiatedMobile,
                ];
            });

            return response()->json([
                'status'  => 200,
                'message' => 'Order marked as invoiced and invoice created successfully.',
                'data'    => [
                    'success'          => true,
                    'so_number'        => $soNo,
                    'invoice_id'       => $result['invoice_id'],
                    'invoice_no'       => $invoiceNo,
                    'invoice_date'     => Carbon::parse($invoiceDate)->format('Y-m-d'),
                    'initiated_mobile' => $result['initiated_mobile'], // ✅ required
                ],
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('Failed to mark order invoiced', ['exception' => $e]);

            return response()->json([
                'status'  => 500,
                'message' => 'Failed to mark order as invoiced.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
