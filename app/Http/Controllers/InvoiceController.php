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
            // ---------- Single record ----------
            if ($id !== null) {
                $o = OrdersModel::with([
                        'clientRef:id,name',
                        'contactRef:id,client,name,designation,mobile,email',
                        'initiatedByRef:id,name,username',
                        'checkedByRef:id,name,username',
                        'dispatchedByRef:id,name,username',
                        'invoiceRef:id,invoice_number,invoice_date', // Eager load invoice details
                    ])
                    ->select(
                        'id', 'company', 'client', 'client_contact_person',
                        'so_no', 'so_date', 'order_no', 'order_date',
                        'invoice', 'status', 'initiated_by', 'checked_by', 'dispatched_by',
                        'drive_link', 'created_at', 'updated_at'
                    )
                    ->find($id);

                if (!$o) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Order not found.',
                    ], 404);
                }

                $data = [
                    'id'            => $o->id,
                    'company'       => $o->company,
                    'so_no'         => $o->so_no,
                    'so_date'       => $o->so_date,
                    'order_no'      => $o->order_no,
                    'order_date'    => $o->order_date,
                    'status'        => $o->status,
                    'client'        => $o->clientRef
                        ? ['id'=>$o->clientRef->id, 'name'=>$o->clientRef->name]
                        : null,
                    'client_contact_person' => $o->contactRef
                        ? [
                            'id' => $o->contactRef->id,
                            'name' => $o->contactRef->name,
                            'designation' => $o->contactRef->designation,
                            'mobile' => $o->contactRef->mobile,
                            'email' => $o->contactRef->email,
                        ] : null,
                    'invoice'       => $o->invoiceRef
                        ? [
                            'id' => $o->invoiceRef->id,
                            'invoice_number' => $o->invoiceRef->invoice_number,
                            'invoice_date' => $o->invoiceRef->invoice_date,
                        ]
                        : null,
                    'initiated_by'  => $o->initiatedByRef ? ['id'=>$o->initiatedByRef->id,'name'=>$o->initiatedByRef->name,'username'=>$o->initiatedByRef->username] : null,
                    'checked_by'    => $o->checkedByRef ? ['id'=>$o->checkedByRef->id,'name'=>$o->checkedByRef->name,'username'=>$o->checkedByRef->username] : null,
                    'dispatched_by' => $o->dispatchedByRef ? ['id'=>$o->dispatchedByRef->id,'name'=>$o->dispatchedByRef->name,'username'=>$o->dispatchedByRef->username] : null,
                    'drive_link'    => $o->drive_link,
                    'created_at'    => $o->created_at,
                    'updated_at'    => $o->updated_at,
                ];

                return response()->json([
                    'code'    => 200,
                    'status'  => true,
                    'message' => 'Order fetched successfully.',
                    'data'    => $data,
                ], 200);
            }

            // ---------- List with filters + pagination ----------
            $limit       = (int) $request->input('limit', 10);
            $offset      = (int) $request->input('offset', 0);
            $search      = trim((string) $request->input('search', '')); // invoice_number, order_no, client name
            $billedBy    = $request->input('billed_by');                  // user ID for billed_by
            $dispatchedBy = $request->input('dispatched_by');             // user ID for dispatched_by
            $dateFrom    = $request->input('date_from');                  // YYYY-MM-DD for invoice_date
            $dateTo      = $request->input('date_to');                    // YYYY-MM-DD for invoice_date

            // Total count BEFORE filters
            $total = OrdersModel::count();

            $q = OrdersModel::with([
                    'clientRef:id,name',
                    'contactRef:id,client,name,designation,mobile,email',
                    'initiatedByRef:id,name,username',
                    'checkedByRef:id,name,username',
                    'dispatchedByRef:id,name,username',
                    'invoiceRef:id,invoice_number,invoice_date', // Eager load invoice details
                ])
                ->select(
                    'id', 'company', 'client', 'client_contact_person',
                    'so_no', 'so_date', 'order_no', 'order_date',
                    'invoice', 'status', 'initiated_by', 'checked_by', 'dispatched_by',
                    'drive_link', 'created_at', 'updated_at'
                )
                ->orderBy('id', 'desc');

            // ----- Filters -----
            if ($search !== '') {
                $q->where(function ($w) use ($search) {
                    $w->where('so_no', 'like', "%{$search}%")
                    ->orWhere('order_no', 'like', "%{$search}%")
                    ->orWhereHas('clientRef', function ($query) use ($search) {
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
                $q->whereDate('invoiceRef.invoice_date', '>=', $dateFrom);
            }
            if (!empty($dateTo)) {
                $q->whereDate('invoiceRef.invoice_date', '<=', $dateTo);
            }

            // Pagination
            $items = $q->skip($offset)->take($limit)->get();
            $count = $items->count(); // how many returned after filters (and paging)

            // Map payload
            $data = $items->map(function ($o) {
                return [
                    'id'        => $o->id,
                    'company'   => $o->company,
                    'so_no'     => $o->so_no,
                    'so_date'   => $o->so_date,
                    'order_no'  => $o->order_no,
                    'order_date'=> $o->order_date,
                    'status'    => $o->status,
                    'client'    => $o->clientRef
                        ? ['id'=>$o->clientRef->id, 'name'=>$o->clientRef->name]
                        : null,
                    'client_contact_person' => $o->contactRef
                        ? [
                            'id' => $o->contactRef->id,
                            'name' => $o->contactRef->name,
                            'designation' => $o->contactRef->designation,
                            'mobile' => $o->contactRef->mobile,
                            'email' => $o->contactRef->email,
                        ] : null,
                    // Invoice information added
                    'invoice' => $o->invoiceRef
                        ? [
                            'id' => $o->invoiceRef->id,
                            'invoice_number' => $o->invoiceRef->invoice_number,
                            'invoice_date' => $o->invoiceRef->invoice_date,
                        ]
                        : null,
                    'initiated_by'  => $o->initiatedByRef ? ['id'=>$o->initiatedByRef->id,'name'=>$o->initiatedByRef->name,'username'=>$o->initiatedByRef->username] : null,
                    'checked_by'    => $o->checkedByRef   ? ['id'=>$o->checkedByRef->id,'name'=>$o->checkedByRef->name,'username'=>$o->checkedByRef->username] : null,
                    'dispatched_by' => $o->dispatchedByRef ? ['id'=>$o->dispatchedByRef->id,'name'=>$o->dispatchedByRef->name,'username'=>$o->dispatchedByRef->username] : null,
                    'drive_link'    => $o->drive_link,
                    'created_at'    => $o->created_at,
                    'updated_at'    => $o->updated_at,
                ];
            });

            return response()->json([
                'code'    => 200,
                'status'  => 'success',
                'message' => 'Orders retrieved successfully.',
                'total'   => $total,      // before filters
                'count'   => $count,      // after filters (and pagination)
                'data'    => $data,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Order fetch failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while fetching orders.',
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


