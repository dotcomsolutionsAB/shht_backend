<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Models\InvoiceModel;     // t_invoice
use App\Models\User;
use App\Models\OrdersModel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\Storage;
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
                        'orderRef:id,so_no,order_no,status,dispatched_by,client',  // Fetching necessary columns from orders
                        'billedByRef:id,name,username',
                        'dispatchedByRef:id,name,username'  // Fetch dispatched_by from User model (assuming it's in `users` table)
                    ])
                    ->select('id', 'order', 'invoice_number', 'invoice_date', 'billed_by', 'dispatched_by', 'created_at', 'updated_at')
                    ->find($id);

                if (!$inv) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Invoice not found.',
                    ], 404);
                }

                $clientObj = null;
                if ($inv->orderRef && $inv->orderRef->client) {
                    $client = ClientsModel::query()
                        ->select('id','name','username')
                        ->find($inv->orderRef->client);
                    if ($client) {
                        $clientObj = [
                            'id'       => $client->id,
                            'name'     => $client->name,
                            'username' => $client->username,
                        ];
                    }
                }

                $data = [
                    'id'             => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'invoice_date'   => $inv->invoice_date,
                    'order'          => $inv->orderRef ? [
                        'id'         => $inv->orderRef->id,
                        'so_no'      => $inv->orderRef->so_no,
                        'order_no'   => $inv->orderRef->order_no,
                        'status'     => $inv->orderRef->status,
                    ] : null,
                    'client'         => $clientObj,
                    'billed_by'      => $inv->billedByRef ? [
                        'id'       => $inv->billedByRef->id,
                        'name'     => $inv->billedByRef->name,
                        'username' => $inv->billedByRef->username,
                    ] : null,
                    'dispatched_by'  => $inv->dispatchedByRef ? [
                        'id'       => $inv->dispatchedByRef->id,
                        'name'     => $inv->dispatchedByRef->name,
                        'username' => $inv->dispatchedByRef->username,
                    ] : null,
                    'created_at'     => $inv->created_at,
                    'updated_at'     => $inv->updated_at,
                ];

                return response()->json([
                    'status'  => true,
                    'message' => 'Invoice fetched successfully.',
                    'data'    => $data,
                ], 200);
            }

            // ---------- List with filters + pagination ----------
            $limit        = (int) $request->input('limit', 10);
            $offset       = (int) $request->input('offset', 0);
            $search       = trim((string) $request->input('search', ''));
            $billedBy     = $request->input('billed_by');
            $dispatchedBy = $request->input('dispatched_by');   // <-- value from orders table
            $dateFrom     = $request->input('date_from');
            $dateTo       = $request->input('date_to');

            /* -----------------------------------------------------------------
            * 1.  Build the query once (will reuse for count() and get())
            * ----------------------------------------------------------------- */
            $q = InvoiceModel::with([
                    'orderRef:id,so_no,order_no,status,dispatched_by,client',   // <- still needed
                    'billedByRef:id,name,username',
                    // dispatcher user will be loaded manually below
                ])
                ->select('id', 'order', 'invoice_number', 'invoice_date',
                        'billed_by', 'created_at', 'updated_at')   // <- NO dispatched_by here
                ->orderBy('id', 'desc');

            /* ------------------ apply filters ------------------ */
            if ($search !== '') {
                $q->where(function ($w) use ($search) {
                    $w->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('orderRef', function ($q) use ($search) {
                        $q->where('order_no', 'like', "%{$search}%")
                            ->orWhere('client', 'like', "%{$search}%");
                    });
                });
            }

            if (!empty($billedBy)) {
                $q->where('billed_by', (int) $billedBy);
            }

            /*  >>>  DISPATCHER FILTER NOW ON orders TABLE  <<<  */
            if (!empty($dispatchedBy)) {
                $q->whereHas('orderRef', function ($q) use ($dispatchedBy) {
                    $q->where('dispatched_by', (int) $dispatchedBy);
                });
            }

            if (!empty($dateFrom)) {
                $q->whereDate('invoice_date', '>=', $dateFrom);
            }
            if (!empty($dateTo)) {
                $q->whereDate('invoice_date', '<=', $dateTo);
            }

            /* -----------------------------------------------------------------
            * 2.  Total AFTER filters
            * ----------------------------------------------------------------- */
            $total = (clone $q)->count();

            /* -----------------------------------------------------------------
            * 3.  Paginate
            * ----------------------------------------------------------------- */
            $items = $q->skip($offset)->take($limit)->get();

            /* -----------------------------------------------------------------
            * 4.  Eager-load the dispatcher user in one go
            *     (orderRef.dispatched_by -> users.id)
            * ----------------------------------------------------------------- */
            $dispatchIds = $items->pluck('orderRef.dispatched_by')
                                ->filter()          // remove nulls
                                ->unique()
                                ->values();
            $dispatchers = User::whereIn('id', $dispatchIds)
                                        ->get(['id', 'name', 'username'])
                                        ->keyBy('id');

            // [client+] bulk load clients
            $clientIds = $items->pluck('orderRef.client')->filter()->unique()->values();
            $clients   = ClientsModel::whereIn('id', $clientIds)
                ->get(['id','name','username'])
                ->keyBy('id');

            /* -----------------------------------------------------------------
            * 5.  Shape the response
            * ----------------------------------------------------------------- */
            $data = $items->map(function ($inv) use ($dispatchers) {
                $dispatcher = $inv->orderRef && $inv->orderRef->dispatched_by
                            ? $dispatchers->get($inv->orderRef->dispatched_by)
                            : null;

                return [
                    'id'             => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'invoice_date'   => $inv->invoice_date,
                    'order'          => $inv->orderRef ? [
                    'id'            => $inv->orderRef->id,
                    'so_no'         => $inv->orderRef->so_no,
                    'order_no'      => $inv->orderRef->order_no,
                    'status'        => $inv->orderRef->status,
                        'dispatched_by' => $dispatcher ? [
                            'id'       => $dispatcher->id,
                            'name'     => $dispatcher->name,
                            'username' => $dispatcher->username,
                        ] : null,
                        'client'        => $client ? [ // [client+]
                            'id'       => $client->id,
                            'name'     => $client->name,
                            'username' => $client->username,
                        ] : null,
                    ] : null,
                    'billed_by'      => $inv->billedByRef ? [
                        'id'       => $inv->billedByRef->id,
                        'name'     => $inv->billedByRef->name,
                        'username' => $inv->billedByRef->username,
                    ] : null,
                    'created_at'     => $inv->created_at,
                    'updated_at'     => $inv->updated_at,
                ];
            });

            return response()->json([
                'code'    => 200,
                'status'  => 'success',
                'message' => 'Invoices retrieved successfully.',
                'total'   => $total,
                'count'   => $data->count(),
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

    /* ----------  NEW  ---------- */
    /** Create invoice for internal use (no HTTP) */
    public function makeInvoice(array $data): InvoiceModel
    {
        // fake request that satisfies your own validation
        $req = new Request($data);

        // --- use the exact same rules you wrote in create() ---
        $req->validate([
            'order'          => ['required', 'integer', 'exists:t_orders,id'],
            'invoice_number' => ['required', 'string', 'max:255', 'unique:t_invoice,invoice_number'],
            'invoice_date'   => ['required', 'date'],
            'billed_by'      => ['required', 'integer', 'exists:users,id'],
        ]);
        
        return DB::transaction(fn () => InvoiceModel::create([
            'order'          => (int) $req->input('order'),
            'invoice_number' => $req->input('invoice_number'),
            'invoice_date'   => $req->input('invoice_date'),
            'billed_by'      => (int) $req->input('billed_by'),
        ]));
    }

    // export
    public function exportExcel(Request $request)
    {
        /* ---------- 1.  identical filter helpers as in fetch() ---------- */
        $search       = trim((string) $request->input('search', ''));
        $billedBy     = $request->input('billed_by');
        $dispatchedBy = $request->input('dispatched_by');
        $dateFrom     = $request->input('date_from');
        $dateTo       = $request->input('date_to');

        /* ---------- 2.  build identical query (no limit/offset) ---------- */
        $q = InvoiceModel::with([
                'orderRef.clientRef:id,name,username',                       // need client name
                'orderRef:id,so_no,order_no,status,dispatched_by',  // keep minimal
                'billedByRef:id,name,username',
            ])
            ->select('id', 'order', 'invoice_number', 'invoice_date', 'billed_by', 'created_at')
            ->orderBy('id', 'desc');

        /* ------------------ apply filters (same as fetch) ------------------ */
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('invoice_number', 'like', "%{$search}%")
                ->orWhereHas('orderRef', function ($q) use ($search) {
                    $q->where('order_no', 'like', "%{$search}%")
                        ->orWhereHas('clientRef', fn($q) => $q->where('name', 'like', "%{$search}%"));
                });
            });
        }
        if (!empty($billedBy)) {
            $q->where('billed_by', (int) $billedBy);
        }
        if (!empty($dispatchedBy)) {
            $q->whereHas('orderRef', fn($q) => $q->where('dispatched_by', (int) $dispatchedBy));
        }
        if (!empty($dateFrom)) {
            $q->whereDate('invoice_date', '>=', $dateFrom);
        }
        if (!empty($dateTo)) {
            $q->whereDate('invoice_date', '<=', $dateTo);
        }

        $rows = $q->get();   // everything that matches filters

        /* ---------- 3.  eager-load dispatcher users in one go ---------- */
        $dispatchIds = $rows->pluck('orderRef.dispatched_by')->filter()->unique()->values();
        $dispatchers = User::whereIn('id', $dispatchIds)
                                    ->get(['id', 'name', 'username'])
                                    ->keyBy('id');

        /* ---------- 4.  prepare Excel ---------- */
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        // headers
        $headers = [
            'Sl No.',
            'Client',
            'Order',
            'So Number',
            'Invoice Number',
            'Invoice Date',
            'Billed By',
            'Dispatched By',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['argb' => '000000'],
                ],
            ],
        ]);

        // data
        $rowNo = 2;
        $sl    = 1;
        foreach ($rows as $inv) {
            $dispatcher = $inv->orderRef && $inv->orderRef->dispatched_by
                        ? $dispatchers->get($inv->orderRef->dispatched_by)
                        : null;

            $sheet->fromArray([
                $sl,
                $inv->orderRef->clientRef->name ?? '',
                $inv->orderRef->order_no ?? '',
                $inv->orderRef->so_no ?? '',
                $inv->invoice_number,
                \Carbon\Carbon::parse($inv->invoice_date)->format('d-m-Y'),
                $inv->billedByRef->name ?? '',
                $dispatcher->name ?? '',
            ], null, "A{$rowNo}");

            $sheet->getStyle("A{$rowNo}:H{$rowNo}")->applyFromArray([
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

        foreach (range('A','H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        /* ---------- 5.  save to disk & return URL ---------- */
        $filename  = 'invoices_export_' . now()->format('Ymd_His') . '.xlsx';
        $directory = 'invoice';                      // storage/app/public/invoice/

        if (!Storage::disk('public')->exists($directory)) {
            Storage::disk('public')->makeDirectory($directory);
        }

        $path = storage_path("app/public/{$directory}/{$filename}");
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        $publicUrl = Storage::disk('public')->url("{$directory}/{$filename}");

        return response()->json([
            'code'     => 200,
            'status'   => true,
            'message'  => 'Invoices exported successfully.',
            'file_url' => $publicUrl,
        ], 200);
    }
}