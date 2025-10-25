<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Models\InvoiceModel;     // t_invoice
use App\Models\User;
use App\Models\OrdersModel;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    // create
    public function create(Request $request)
    {
        try {
            // 1️⃣ Validate input
            $request->validate([
                'order'          => ['required', 'integer', 'exists:t_orders,id'],
                'invoice_number' => ['required', 'string', 'max:255', 'unique:t_invoice,invoice_number'],
                'invoice_date'   => ['required', 'date'],
                'billed_by'      => ['required', 'integer', 'exists:users,id'],
            ]);

            // 2️⃣ Create inside a transaction
            $invoice = DB::transaction(function () use ($request) {
                return InvoiceModel::create([
                    'order'          => (int) $request->input('order'),
                    'invoice_number' => $request->input('invoice_number'),
                    'invoice_date'   => $request->input('invoice_date'),
                    'billed_by'      => (int) $request->input('billed_by'),
                ]);
            });

            // 3️⃣ Load related objects
            $invoice->load([
                'orderRef:id,so_no,order_no,status',
                'billedByRef:id,name,username',
            ]);

            // 4️⃣ Build response
            return response()->json([
                'code'    => 201,
                'status'  => true,
                'message' => 'Invoice created successfully!',
                'data'    => [
                    'id'             => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date'   => $invoice->invoice_date,
                    'order'          => $invoice->orderRef
                        ? [
                            'id'        => $invoice->orderRef->id,
                            'so_no'     => $invoice->orderRef->so_no,
                            'order_no'  => $invoice->orderRef->order_no,
                            'status'    => $invoice->orderRef->status,
                        ]
                        : null,
                    'billed_by' => $invoice->billedByRef
                        ? [
                            'id'       => $invoice->billedByRef->id,
                            'name'     => $invoice->billedByRef->name,
                            'username' => $invoice->billedByRef->username,
                        ]
                        : null,
                    'created_at' => $invoice->created_at,
                ],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation error!',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Invoice creation failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while creating invoice.',
            ], 500);
        }
    }

    // fetch
    // public function fetch(Request $request, $id = null)
    // {
    //     try {
    //         // ---------- Single invoice by ID ----------
    //         if ($id !== null) {
    //             $inv = InvoiceModel::with([
    //                     'orderRef:id,so_no,order_no,status',
    //                     'billedByRef:id,name,username',
    //                 ])
    //                 ->select('id','order','invoice_number','invoice_date','billed_by','created_at','updated_at')
    //                 ->find($id);

    //             if (! $inv) {
    //                 return response()->json([
    //                     'status'  => false,
    //                     'message' => 'Invoice not found.',
    //                 ], 404);
    //             }

    //             $data = [
    //                 'id'             => $inv->id,
    //                 'invoice_number' => $inv->invoice_number,
    //                 'invoice_date'   => $inv->invoice_date,
    //                 'order'          => $inv->orderRef ? [
    //                     'id'         => $inv->orderRef->id,
    //                     'so_no'      => $inv->orderRef->so_no,
    //                     'order_no'   => $inv->orderRef->order_no,
    //                     'status'     => $inv->orderRef->status,
    //                 ] : null,
    //                 'billed_by'      => $inv->billedByRef ? [
    //                     'id'       => $inv->billedByRef->id,
    //                     'name'     => $inv->billedByRef->name,
    //                     'username' => $inv->billedByRef->username,
    //                 ] : null,
    //                 'created_at'     => $inv->created_at,
    //                 'updated_at'     => $inv->updated_at,
    //             ];

    //             return response()->json([
    //                 'status'  => true,
    //                 'message' => 'Invoice fetched successfully.',
    //                 'data'    => $data,
    //             ], 200);
    //         }

    //         // ---------- List with limit/offset ----------
    //         $limit  = (int) $request->input('limit', 10);
    //         $offset = (int) $request->input('offset', 0);

    //         $items = InvoiceModel::with([
    //                 'orderRef:id,so_no,order_no,status',
    //                 'billedByRef:id,name,username',
    //             ])
    //             ->select('id','order','invoice_number','invoice_date','billed_by','created_at','updated_at')
    //             ->orderBy('id', 'desc')
    //             ->skip($offset)->take($limit)
    //             ->get();

    //         $data = $items->map(function ($inv) {
    //             return [
    //                 'id'             => $inv->id,
    //                 'invoice_number' => $inv->invoice_number,
    //                 'invoice_date'   => $inv->invoice_date,
    //                 'order'          => $inv->orderRef ? [
    //                     'id'       => $inv->orderRef->id,
    //                     'so_no'    => $inv->orderRef->so_no,
    //                     'order_no' => $inv->orderRef->order_no,
    //                     'status'   => $inv->orderRef->status,
    //                 ] : null,
    //                 'billed_by'      => $inv->billedByRef ? [
    //                     'id'       => $inv->billedByRef->id,
    //                     'name'     => $inv->billedByRef->name,
    //                     'username' => $inv->billedByRef->username,
    //                 ] : null,
    //                 'created_at'     => $inv->created_at,
    //                 'updated_at'     => $inv->updated_at,
    //             ];
    //         });

    //         return response()->json([
    //             'status'  => true,
    //             'message' => 'Invoices fetched successfully.',
    //             'count'   => $data->count(),
    //             'data'    => $data,
    //         ], 200);

    //     } catch (\Throwable $e) {
    //         Log::error('Invoice fetch failed', [
    //             'error' => $e->getMessage(),
    //             'file'  => $e->getFile(),
    //             'line'  => $e->getLine(),
    //         ]);

    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Something went wrong while fetching invoices.',
    //         ], 500);
    //     }
    // }
    public function fetch(Request $request, $id = null)
    {
        try {
            // ---------- Single invoice by ID ----------
            if ($id !== null) {
                $inv = InvoiceModel::with([
                        'orderRef:id,so_no,order_no,status',
                        'billedByRef:id,name,username',
                        'dispatchedByRef:id,name,username', // Dispatched by from the order
                    ])
                    ->select('id', 'order', 'invoice_number', 'invoice_date', 'billed_by', 'dispatched_by', 'created_at', 'updated_at')
                    ->find($id);

                if (!$inv) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Invoice not found.',
                    ], 404);
                }

                // Shape the data for single invoice
                $data = [
                    'id'               => $inv->id,
                    'invoice_number'   => $inv->invoice_number,
                    'invoice_date'     => $inv->invoice_date,
                    'client'           => $inv->orderRef ? [
                        'id'         => $inv->orderRef->clientRef->id ?? null,
                        'name'       => $inv->orderRef->clientRef->name ?? null,
                    ] : null,
                    'order_no'         => $inv->orderRef ? $inv->orderRef->order_no : null,
                    'so_number'        => $inv->orderRef ? $inv->orderRef->so_no : null,
                    'billed_by'        => $inv->billedByRef ? [
                        'id'         => $inv->billedByRef->id,
                        'name'       => $inv->billedByRef->name,
                        'username'   => $inv->billedByRef->username,
                    ] : null,
                    'dispatched_by'    => $inv->dispatchedByRef ? [
                        'id'         => $inv->dispatchedByRef->id,
                        'name'       => $inv->dispatchedByRef->name,
                        'username'   => $inv->dispatchedByRef->username,
                    ] : null,
                    'created_at'       => $inv->created_at,
                    'updated_at'       => $inv->updated_at,
                ];

                return response()->json([
                    'status'  => true,
                    'message' => 'Invoice fetched successfully.',
                    'data'    => $data,
                ], 200);
            }

            // ---------- List with filters + pagination ----------
            $limit       = (int) $request->input('limit', 10);
            $offset      = (int) $request->input('offset', 0);
            $search      = trim((string) $request->input('search', ''));     // Search based on invoice_no, order_no, or client
            $billedBy    = $request->input('billed_by');                     // User ID for billed_by
            $dispatchedBy = $request->input('dispatched_by');                // User ID for dispatched_by
            $dateFrom    = $request->input('date_from');                     // YYYY-MM-DD
            $dateTo      = $request->input('date_to');                       // YYYY-MM-DD

            // Log the search filter details
            \Log::info("Fetching invoices with filters:", [
                'search'      => $search,
                'billedBy'    => $billedBy,
                'dispatchedBy' => $dispatchedBy,
                'dateFrom'    => $dateFrom,
                'dateTo'      => $dateTo,
            ]);

            // Total before filters
            $total = InvoiceModel::count();

            $q = InvoiceModel::with([
                    'orderRef:id,so_no,order_no,status',
                    'billedByRef:id,name,username',
                    'dispatchedByRef:id,name,username', // Dispatched by from order
                ])
                ->select('id', 'order', 'invoice_number', 'invoice_date', 'billed_by', 'dispatched_by', 'created_at', 'updated_at')
                ->orderBy('id', 'desc');

            // ----- Filters -----
            if ($search !== '') {
                $q->where(function ($w) use ($search) {
                    $w->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('order_no', 'like', "%{$search}%")
                    ->orWhereHas('orderRef.clientRef', function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%");
                    });
                });
            }

            if (!empty($billedBy)) {
                $q->where('billed_by', (int) $billedBy);
            }
            if (!empty($dispatchedBy)) {
                $q->where('dispatched_by', (int) $dispatchedBy);
            }
            if (!empty($dateFrom)) {
                $q->whereDate('invoice_date', '>=', $dateFrom);
            }
            if (!empty($dateTo)) {
                $q->whereDate('invoice_date', '<=', $dateTo);
            }

            // Pagination
            $items = $q->skip($offset)->take($limit)->get();
            $count = $items->count(); // how many returned after filters and pagination

            // Build data
            $data = $items->map(function ($inv) {
                return [
                    'id'               => $inv->id,
                    'invoice_number'   => $inv->invoice_number,
                    'invoice_date'     => $inv->invoice_date,
                    'client'           => $inv->orderRef ? [
                        'id'         => $inv->orderRef->clientRef->id ?? null,
                        'name'       => $inv->orderRef->clientRef->name ?? null,
                    ] : null,
                    'order_no'         => $inv->orderRef ? $inv->orderRef->order_no : null,
                    'so_number'        => $inv->orderRef ? $inv->orderRef->so_no : null,
                    'billed_by'        => $inv->billedByRef ? [
                        'id'         => $inv->billedByRef->id,
                        'name'       => $inv->billedByRef->name,
                        'username'   => $inv->billedByRef->username,
                    ] : null,
                    'dispatched_by'    => $inv->dispatchedByRef ? [
                        'id'         => $inv->dispatchedByRef->id,
                        'name'       => $inv->dispatchedByRef->name,
                        'username'   => $inv->dispatchedByRef->username,
                    ] : null,
                    'created_at'       => $inv->created_at,
                    'updated_at'       => $inv->updated_at,
                ];
            });

            return response()->json([
                'status'  => true,
                'message' => 'Invoices retrieved successfully.',
                'total'   => $total,      // before filters
                'count'   => $count,      // after filters and pagination
                'data'    => $data,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Invoice fetch failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while fetching invoices.',
            ], 500);
        }
    }

    // edit
    public function edit(Request $request, $id)
    {
        try {
            // 1) Ensure exists
            $inv = InvoiceModel::find($id);
            if (! $inv) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invoice not found.',
                ], 404);
            }

            // 2) Validate
            $request->validate([
                'order'          => ['required','integer','exists:t_orders,id'],
                'invoice_number' => [
                    'required','string','max:255',
                    Rule::unique('t_invoice','invoice_number')->ignore($id)
                ],
                'invoice_date'   => ['required','date'],
                'billed_by'      => ['required','integer','exists:users,id'],
            ]);

            // 3) Edit (transaction)
            DB::transaction(function () use ($id, $request) {
                InvoiceModel::where('id', $id)->update([
                    'order'          => (int) $request->order,
                    'invoice_number' => $request->invoice_number,
                    'invoice_date'   => $request->invoice_date,
                    'billed_by'      => (int) $request->billed_by,
                ]);
            });

            // 4) Fresh with relations
            $fresh = InvoiceModel::with([
                    'orderRef:id,so_no,order_no,status',
                    'billedByRef:id,name,username',
                ])->find($id);

            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'Invoice updated successfully!',
                'data'    => [
                    'id'             => $fresh->id,
                    'invoice_number' => $fresh->invoice_number,
                    'invoice_date'   => $fresh->invoice_date,
                    'order'          => $fresh->orderRef ? [
                        'id'       => $fresh->orderRef->id,
                        'so_no'    => $fresh->orderRef->so_no,
                        'order_no' => $fresh->orderRef->order_no,
                        'status'   => $fresh->orderRef->status,
                    ] : null,
                    'billed_by'      => $fresh->billedByRef ? [
                        'id'       => $fresh->billedByRef->id,
                        'name'     => $fresh->billedByRef->name,
                        'username' => $fresh->billedByRef->username,
                    ] : null,
                    'updated_at'     => $fresh->updated_at,
                ],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code'    => 422,
                'status'  => false,
                'message' => 'Validation error!',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Invoice update failed', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);
            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while updating invoice.',
            ], 500);
        }
    }

    // delete
    public function delete(Request $request, $id)
    {
        try {
            // 1) Load with relations for snapshot
            $inv = InvoiceModel::with([
                    'orderRef:id,so_no,order_no,status',
                    'billedByRef:id,name,username',
                ])
                ->select('id','order','invoice_number','invoice_date','billed_by','created_at','updated_at')
                ->find($id);

            if (! $inv) {
                return response()->json([
                    'code'    => 404,
                    'status'  => false,
                    'message' => 'Invoice not found.',
                ], 404);
            }

            // 2) Build snapshot
            $snapshot = [
                'id'             => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'invoice_date'   => $inv->invoice_date,
                'order'          => $inv->orderRef ? [
                    'id'       => $inv->orderRef->id,
                    'so_no'    => $inv->orderRef->so_no,
                    'order_no' => $inv->orderRef->order_no,
                    'status'   => $inv->orderRef->status,
                ] : null,
                'billed_by'      => $inv->billedByRef ? [
                    'id'       => $inv->billedByRef->id,
                    'name'     => $inv->billedByRef->name,
                    'username' => $inv->billedByRef->username,
                ] : null,
                'created_at'     => $inv->created_at,
                'updated_at'     => $inv->updated_at,
            ];

            // 3) Delete (transaction)
            DB::transaction(function () use ($inv) {
                $inv->delete();
            });

            // 4) Respond
            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'Invoice deleted successfully!',
                'data'    => $snapshot,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Invoice delete failed', [
                'code'       => 500,
                'invoice_id' => $id,
                'error'      => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);
            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while deleting invoice.',
            ], 500);
        }
    }
}


