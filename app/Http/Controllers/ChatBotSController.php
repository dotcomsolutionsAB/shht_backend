<?php

namespace App\Http\Controllers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\OrdersModel;
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

        // 2) Build query using clientRef relation
        $query = OrdersModel::with('clientRef');

        if ($clientStr !== '') {
            $query->whereHas('clientRef', function ($q) use ($clientStr) {
                $q->where('name', 'like', '%' . $clientStr . '%');
            });
        }

        // 3) Total count for pagination
        $total = $query->count();

        // 4) Fetch paginated records (newest first; adjust orderBy as per your need)
        $offset = ($page - 1) * $perPage;

        $orders = $query
            ->orderBy('so_date', 'desc')   // or 'id' / 'order_date' depending on your business rule
            ->skip($offset)
            ->take($perPage)
            ->get();

        // 5) Build content string + json array
        $lines = [];
        $json  = [""];
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
}
