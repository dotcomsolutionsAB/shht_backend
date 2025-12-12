<?php

namespace App\Http\Controllers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\OrdersModel;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ChatBotSController extends Controller
{
    //
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
        $query = OrdersModel::with('clientRef')
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
'
SN: %d
Client: *%s* 
Order No: %s 
Order Date: %s
Order Value: %.2f
',
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
        // 1) Validate 12 digits
        $validator = Validator::make($request->all(), [
            'mobile' => ['required', 'digits:12'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 422,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $mobile12 = $request->input('mobile');           // e.g. 911234567890
        $mobile10 = substr($mobile12, -10);              // e.g. 1234567890

        // Possible DB formats:
        $patterns = [
            $mobile12,                   // 911234567890
            "+$mobile12",                // +911234567890
            $mobile10,                   // 1234567890
            "+91$mobile10",              // +911234567890 (if stored differently)
        ];

        // 2) Query using LIKE for all patterns
        $user = User::where(function ($q) use ($patterns) {
            foreach ($patterns as $p) {
                $q->orWhere('mobile', 'like', '%' . $p . '%');
            }
        })->first();

        // 3) If not found
        if (! $user) {
            return response()->json([
                'status' => 200,
                'exists' => false,
                'role'   => null,
            ], 200);
        }

        // 4) If found
        return response()->json([
            'status' => 200,
            'exists' => true,
            'role'   => $user->role ?? null,
            'id'   => $user->id ?? null,
        ], 200);
    }

    public function getDispatchUsers(): JsonResponse
    {
        // Fetch all users with role = 'dispatch'
        $dispatchUsers = User::where('role', 'dispatch')
            ->orderBy('name', 'asc') // or 'id' if you prefer
            ->get();

        // Build content string and JSON array
        $lines = [];
        $json  = [""];  // first element always blank as per your spec
        $sn    = 1;

        foreach ($dispatchUsers as $user) {
            $lines[] = sprintf('%d. %s', $sn, $user->name);
            $json[]  = $user->name;
            $sn++;
        }

        // If multiple records → join with single space between entries
        $content = implode(' ', $lines);

        return response()->json([
            'status'  => 200,
            'content' => $content,   // e.g. "1. Shabaz 2. Tapas ..."
            'json'    => $json,      // ["", "Shabaz", "Tapas", ...]
        ], 200);
    }

    public function getOrdersByMobile(Request $request): JsonResponse
    {
        $mobile = $request->input('mobile');

        // 1) Build query: match mobile using LIKE to handle +91 prefix in DB
        $orders = OrdersModel::with('clientRef')
            ->where('mobile', 'like', '%' . $mobile . '%')
            ->orderBy('so_date', 'desc')    // or 'id' / 'order_date'
            ->get();

        // 2) If no orders found
        if ($orders->isEmpty()) {
            return response()->json([
                'status'  => 404,
                'message' => "No orders found for mobile {$mobile}",
            ], 404);
        }

        // 3) Build content string + json array (same style as previous API)
        $lines = [];
        $json  = [""];
        $sn    = 1;

        foreach ($orders as $order) {
            $clientName = $order->clientRef->name ?? '';

            $soNo = $order->so_no ?? '';

            $date = $order->so_date
                ? Carbon::parse($order->so_date)->format('d-m-Y')
                : '';

            // 👉 Change this to your actual amount column
            // e.g. $order->total, $order->grand_total, etc.
            $orderValue = $order->order_value ?? 0;

            $line = sprintf(
                'SN: %d Client: %s Order No: %s Order Date: %s Order Value: %.2f',
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

        // Join multiple lines with two spaces
        $content = implode('  ', $lines);

        return response()->json([
            'status'   => 200,
            'content'  => $content,
            'json'     => $json,
        ], 200);
    }

    public function getOrderDetails(Request $request): JsonResponse
    {
        // Validate the input (order_no)
        $validator = Validator::make($request->all(), [
            'order_no' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 422,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $orderNo = trim($request->input('order_no')); // Order number to search

        // Find the order based on the order_no
        $order = OrdersModel::with('clientRef') // Assuming clientRef is the relationship in the OrdersModel
            ->where('order_no', $orderNo)
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

        // Prepare the response data
        $orderDetails = [
            'client' => [
                'id'   => $client->id ?? '',
                'name' => $client->name ?? '',
            ],
            'so_number' => $order->so_no ?? '',
            'so_date'   => $order->so_date ? \Carbon\Carbon::parse($order->so_date)->format('d-m-Y') : '',
            'order_no'  => $order->order_no ?? '',
            'order_date'=> $order->order_date ? \Carbon\Carbon::parse($order->order_date)->format('d-m-Y') : '',
            'status'    => $order->status ?? '',
            'folder_link' => $order->folder_link ?? '',  // Assuming this field exists in the OrdersModel
        ];

        // Prepare the content and json structure in the desired format
        $content = "1. " . $client->name;
        $json = ["", $client->name];  // Start with an empty string, followed by the client name

        return response()->json([
            'status'  => 200,
            'message' => 'Order details fetched successfully.',
            'data'    => $orderDetails,
            'content' => $content,
            'json'     => $json,
        ], 200);
    }
}
