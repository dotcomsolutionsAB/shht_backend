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
        // 1) Validate input
        $validator = Validator::make($request->all(), [
            'client' => ['required', 'string', 'max:255'],
            'page'   => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'page'   => 1,
                'has_more' => false,
                'content'  => '',
                'json'     => [""],
                'errors'   => $validator->errors(),
            ], 422);
        }

        $data      = $validator->validated();
        $clientStr = trim($data['client']);
        $page      = isset($data['page']) ? (int)$data['page'] : 1;
        if ($page < 1) {
            $page = 1;
        }

        $perPage = 5;
        $offset  = ($page - 1) * $perPage;

        // 2) Build query: filter by client name (assuming relation `client` with `name` column)
        $query = OrdersModel::with('client')   // make sure you have relation client() on OrdersModel
            ->whereHas('client', function ($q) use ($clientStr) {
                $q->where('name', 'like', '%' . $clientStr . '%');
            });

        // total count (for has_more)
        $total = $query->count();

        // 3) Fetch page of results
        $orders = $query
            ->orderBy('order_date', 'desc')   // or so_date if you prefer
            ->skip($offset)
            ->take($perPage)
            ->get();

        // 4) Build `content` string & `json` array
        $lines = [];
        $json  = [""];
        $snStart = $offset + 1;

        foreach ($orders as $index => $order) {
            $sn          = $snStart + $index;
            $clientName  = $order->client->name ?? '';
            // adjust these field names to your actual columns
            $orderNo     = $order->so_no ?? $order->order_no ?? '';
            $orderDate   = $order->order_date
                ? \Carbon\Carbon::parse($order->order_date)->format('d-m-Y')
                : '';
            $orderValue  = $order->total ?? $order->order_value ?? '0.00';

            $lines[] = sprintf(
                'SN: %d Client: %s Order No: %s Order Date: %s Order Value: %0.2f',
                $sn,
                $clientName,
                $orderNo,
                $orderDate,
                $orderValue
            );

            if ($orderNo !== '') {
                $json[] = $orderNo;
            }
        }

        // double-space separated content
        $content = implode('  ', $lines);

        // 5) has_more logic
        $hasMore = $total > ($offset + $orders->count());

        return response()->json([
            'status'   => 200,
            'page'     => $page,
            'has_more' => $hasMore,
            'content'  => $content,
            'json'     => $json,
        ], 200);
    }
}
