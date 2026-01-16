<?php

namespace App\Http\Controllers;
use App\Models\OrdersModel;
use App\Models\CounterModel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class OrdersController extends Controller
{
    //
    public function __construct(private CounterController $counter) {}

    // create
    public function create(Request $request)
    {
        try {
            // 1) Validate request
            $request->validate([
                'company'                => ['required', Rule::in(['SHHT','SHAPL'])],
                'client'                 => ['required','integer','exists:t_clients,id'],
                'client_contact_person'  => ['required','integer','exists:t_clients_contact_person,id'],

                // you asked to provide order_no (unique). We will generate only so_no from counter.
                'order_no'               => ['required','string','max:255','unique:t_orders,order_no'],

                'email'                 => ['required','email', 'max:255'],
                'mobile'                => ['required','string', 'max:255'],

                'so_date'                => ['required','date'],
                'order_date'             => ['required','date'],
                'order_value' => ['required', 'numeric', 'min:0'],

                'invoice'                => ['nullable','integer','exists:t_invoice,id'],

                'status' => ['nullable', Rule::in([
                    'pending','dispatched','partial_pending','invoiced',
                    'completed','short_closed','cancelled','out_of_stock'
                ])],

                // 'initiated_by'           => ['required','integer','exists:users,id'],
                'checked_by'             => ['required','integer','exists:users,id'],
                // 'dispatched_by'          => ['required','integer','exists:users,id'],

                'drive_link'             => ['nullable','string','max:255'],
            ]);

            // 2) Create inside a DB transaction (includes counter reservation)
            $order = DB::transaction(function () use ($request) {

            $company = strtoupper(trim($request->company));

            // ✅ Lock counter row
            $counter = CounterModel::where('prefix', $company)
                ->lockForUpdate()
                ->first();

            if (!$counter) {
                // first record (no FY auto postfix)
                $counter = CounterModel::create([
                    'prefix'  => $company,
                    'number'  => 1,
                    'postfix' => '', // keep blank (or null if your column allows)
                ]);
            }

            // ✅ Build expected SO number using DB postfix ONLY
            $postfix = trim((string) $counter->postfix);

            $expectedSoNo = $postfix !== ''
                ? sprintf('%s/%03d/%s', $counter->prefix, (int)$counter->number, $postfix)
                : sprintf('%s/%03d', $counter->prefix, (int)$counter->number);

            // ✅ verify request so_no matches expected
            if (trim((string)$request->so_no) !== $expectedSoNo) {
                throw new \Exception("Invalid so_no. Expected: {$expectedSoNo}");
            }

            $status = $request->input('status', 'pending');

            $order = OrdersModel::create([
                'company'               => $company,
                'client'                => (int) $request->client,
                'client_contact_person' => (int) $request->client_contact_person,

                'email'                 => $request->email,
                'mobile'                => $request->mobile,

                'so_no'                 => $expectedSoNo,
                'so_date'               => $request->so_date,

                'order_no'              => $request->order_no,
                'order_date'            => $request->order_date,
                'order_value'           => $request->order_value,

                'invoice'               => $request->invoice,
                'status'                => $status,

                'initiated_by'          => auth()->id(),
                'checked_by'            => (int) $request->checked_by,
                'drive_link'            => $request->drive_link,
            ]);

            // ✅ increment AFTER success
            $counter->number = (int)$counter->number + 1;
            $counter->save();

            return $order;
        });

            // 3) Success response
            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'Order created successfully!',
                'data'    => [
                    'id'                    => $order->id,
                    'company'               => $order->company,
                    'so_no'                 => $order->so_no,
                    'so_date'               => $order->so_date,
                    'order_no'              => $order->order_no,
                    'order_date'            => $order->order_date,
                    'order_value'           => $order->order_value,
                    'status'                => $order->status,
                    'client'                => $order->client,
                    'client_contact_person' => $order->client_contact_person,
                    'email'                 => $order->email,
                    'mobile'                => $order->mobile,
                    'invoice'               => $order->invoice,
                    'initiated_by'          => $order->initiated_by,
                    'checked_by'            => $order->checked_by,
                    'dispatched_by'         => $order->dispatched_by,
                    'drive_link'            => $order->drive_link,
                    'created_at'            => $order->created_at,
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
            Log::error('Order create failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while creating order!',
            ], 500);
        }
    }

    // fetch
    public function fetch(Request $request, $id = null)
    {
        try {
            // ---------- single order by id ----------
            if ($id !== null) {
                $o = OrdersModel::with([
                        'clientRef:id,name',
                        'contactRef:id,client,name,rm,mobile,email',
                        'initiatedByRef:id,name,username',
                        'checkedByRef:id,name,username',
                        'dispatchedByRef:id,name,username',
                        // 'invoiceRef:id,....' // if available
                        'invoiceRef:id,invoice_number,invoice_date',
                    ])
                    ->select(
                        'id','company','client','client_contact_person',
                        'email','mobile',
                        'so_no','so_date','order_no','order_date','order_value',
                        'invoice','status',
                        'initiated_by','checked_by','dispatched_by',
                        'dispatched_date', 'drive_link','created_at','updated_at'
                    )
                    ->find($id);

                if (! $o) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Order not found.',
                    ], 404);
                }

                $data = [
                    'id'        => $o->id,
                    'company'   => $o->company,
                    'so_no'     => $o->so_no,
                    'so_date'   => $o->so_date,
                    'order_no'  => $o->order_no,
                    'order_date'=> $o->order_date,
                    'order_value'=> $o->order_value,
                    'status'    => $o->status,
                    'dispatched_date' => $o->dispatched_date,
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
                    'email'  => $o->email,
                    'mobile' => $o->mobile,
                     // Expand invoice info (invoice_number, invoice_date)
                    'invoice'       => $o->invoiceRef
                        ? [
                            'id' => $o->invoiceRef->id,
                            'invoice_number' => $o->invoiceRef->invoice_number,
                            'invoice_date' => $o->invoiceRef->invoice_date,
                        ]
                        : null,
                    'initiated_by'  => $o->initiatedByRef ? ['id'=>$o->initiatedByRef->id,'name'=>$o->initiatedByRef->name,'username'=>$o->initiatedByRef->username] : null,
                    'checked_by'    => $o->checkedByRef   ? ['id'=>$o->checkedByRef->id,'name'=>$o->checkedByRef->name,'username'=>$o->checkedByRef->username]     : null,
                    'dispatched_by' => $o->dispatchedByRef? ['id'=>$o->dispatchedByRef->id,'name'=>$o->dispatchedByRef->name,'username'=>$o->dispatchedByRef->username] : null,
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

            // ---------- list with limit/offset + optional filters ----------
            $limit         = (int) $request->input('limit', 10);
            $offset        = (int) $request->input('offset', 0);
            $search        = trim((string) $request->input('search', ''));     // so_no or order_no
            $client        = $request->input('client');                         // id
            $status        = $request->input('status');                         // enum
            $checkedBy     = $request->input('checked_by');                     // user id
            $dispatchedBy  = $request->input('dispatched_by');                  // user id
            $dateFrom      = $request->input('date_from');                      // YYYY-MM-DD
            $dateTo        = $request->input('date_to');                        // YYYY-MM-DD

            // Total BEFORE any filters (as you requested)
            $total = OrdersModel::count();

            $q = OrdersModel::with([
                    'clientRef:id,name',
                    'contactRef:id,client,name,rm',
                    'initiatedByRef:id,name,username',
                    'checkedByRef:id,name,username',
                    'dispatchedByRef:id,name,username',
                    'invoiceRef:id,invoice_number,invoice_date',
                ])
                ->select(
                    'id','company','client','client_contact_person',
                    'mobile','email',
                    'so_no','so_date','order_no','order_date','order_value',
                    'invoice','status',
                    'initiated_by','checked_by','dispatched_by',
                    'dispatched_date','drive_link','created_at','updated_at'
                )
                ->orderBy('id','desc');

            // ----- Filters (all optional) -----
            if ($search !== '') {
                $q->where(function ($w) use ($search) {
                    $w->where('so_no', 'like', "%{$search}%")
                    ->orWhere('order_no', 'like', "%{$search}%");
                });
            }
            if (!empty($client)) {
                $q->where('client', (int) $client);
            }
            if (!empty($status)) {
                $q->where('status', $status);
            }
            if (!empty($checkedBy)) {
                $q->where('checked_by', (int) $checkedBy);
            }
            if (!empty($dispatchedBy)) {
                $q->where('dispatched_by', (int) $dispatchedBy);
            }
            if (!empty($dateFrom)) {
                $q->whereDate('order_date', '>=', $dateFrom);
            }
            if (!empty($dateTo)) {
                $q->whereDate('order_date', '<=', $dateTo);
            }

            // Paginate
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
                    'order_value'=> $o->order_value,
                    'status'    => $o->status,
                    'dispatched_date'    => $o->dispatched_date,
                    'client'    => $o->clientRef
                        ? ['id'=>$o->clientRef->id, 'name'=>$o->clientRef->name]
                        : null,
                    'client_contact_person' => $o->contactRef
                        ? [
                            'id' => $o->contactRef->id,
                            'name' => $o->contactRef->name,
                            'rm' => $o->contactRef->rm,
                            'mobile' => $o->contactRef->mobile,
                            'email' => $o->contactRef->email,
                        ] : null,
                    'email'  => $o->email,
                    'mobile' => $o->mobile,

                    // Full invoice object
                    'invoice' => $o->invoiceRef
                        ? [
                            'id'             => $o->invoiceRef->id,
                            'invoice_number' => $o->invoiceRef->invoice_number,
                            'invoice_date'   => $o->invoiceRef->invoice_date,
                        ]
                        : null,
                    'initiated_by'  => $o->initiatedByRef ? ['id'=>$o->initiatedByRef->id,'name'=>$o->initiatedByRef->name,'username'=>$o->initiatedByRef->username] : null,
                    'checked_by'    => $o->checkedByRef   ? ['id'=>$o->checkedByRef->id,'name'=>$o->checkedByRef->name,'username'=>$o->checkedByRef->username]     : null,
                    'dispatched_by' => $o->dispatchedByRef? ['id'=>$o->dispatchedByRef->id,'name'=>$o->dispatchedByRef->name,'username'=>$o->dispatchedByRef->username] : null,
                    'drive_link'    => $o->drive_link,
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
            // 1️⃣ Find the order
            $order = OrdersModel::find($id);
            if (! $order) {
                return response()->json([
                    'code'    => 404,
                    'status'  => false,
                    'message' => 'Order not found.',
                ], 404);
            }

            // 2️⃣ Validate input
            $request->validate([
                'company'               => ['required', Rule::in(['SHHT', 'SHAPL'])],
                'client'                => ['required', 'integer', 'exists:t_clients,id'],
                'client_contact_person' => ['required', 'integer', 'exists:t_clients_contact_person,id'],
                'email'                 => ['required','email', 'max:255'],
                'mobile'                => ['required','string', 'max:255'],
                'so_date'               => ['required', 'date'],
                'order_no'              => [
                    'required', 'string', 'max:255',
                    Rule::unique('t_orders', 'order_no')->ignore($id)
                ],
                'order_date'            => ['required', 'date'],
                'order_value'           => ['required', 'numeric', 'min:0'],
                'invoice'               => ['nullable', 'integer'],
                'status'                => ['required', Rule::in([
                    'pending',
                    'dispatched',
                    'partial_pending',
                    'invoiced',
                    'completed',
                    'short_closed',
                    'cancelled',
                    'out_of_stock'
                ])],
                'initiated_by'          => ['required', 'integer', 'exists:users,id'],
                'checked_by'            => ['required', 'integer', 'exists:users,id'],
                'dispatched_by'         => ['required', 'integer', 'exists:users,id'],
                'drive_link'            => ['nullable', 'string', 'max:255'],
            ]);

            // 3️⃣ so_no is NOT editable
            $payload = [
                'company'               => $request->company,
                'client'                => $request->client,
                'client_contact_person' => $request->client_contact_person,
                'email'                 =>$request->email,
                'mobile'                =>$request->mobile,
                'so_date'               => $request->so_date,
                'order_no'              => $request->order_no,
                'order_date'            => $request->order_date,
                'order_value'           => $request->order_value,
                'invoice'               => $request->invoice,
                'status'                => $request->status,
                'initiated_by'          => $request->initiated_by,
                'checked_by'            => $request->checked_by,
                'dispatched_by'         => $request->dispatched_by,
                'drive_link'            => $request->drive_link,
            ];

            DB::transaction(function () use ($id, $payload) {
                OrdersModel::where('id', $id)->update($payload);
            });

            // 4️⃣ Fetch updated record with relations
            $fresh = OrdersModel::with([
                'clientRef:id,name',
                'contactRef:id,name,mobile,email,rm',
                'initiatedByRef:id,name,username',
                'checkedByRef:id,name,username',
                'dispatchedByRef:id,name,username',
            ])->find($id);

            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'Order updated successfully!',
                'data'    => [
                    'id'                 => $fresh->id,
                    'company'            => $fresh->company,
                    'so_no'              => $fresh->so_no,
                    'so_date'            => $fresh->so_date,
                    'order_no'           => $fresh->order_no,
                    'order_date'         => $fresh->order_date,
                    'order_value'        => $fresh->order_value,
                    'status'             => $fresh->status,
                    'drive_link'         => $fresh->drive_link,
                    'client'             => $fresh->clientRef ? ['id'=>$fresh->clientRef->id, 'name'=>$fresh->clientRef->name] : null,
                    'client_contact_person' => $fresh->contactRef ? [
                        'id' => $fresh->contactRef->id,
                        'name' => $fresh->contactRef->name,
                        'rm' => $fresh->contactRef->rm,
                        'mobile' => $fresh->contactRef->mobile,
                        'email' => $fresh->contactRef->email,
                    ] : null,
                    'email'            => $fresh->email,
                    'mobile'            => $fresh->mobile,
                    'initiated_by'  => $fresh->initiatedByRef ? ['id'=>$fresh->initiatedByRef->id,'name'=>$fresh->initiatedByRef->name,'username'=>$fresh->initiatedByRef->username] : null,
                    'checked_by'    => $fresh->checkedByRef   ? ['id'=>$fresh->checkedByRef->id,'name'=>$fresh->checkedByRef->name,'username'=>$fresh->checkedByRef->username] : null,
                    'dispatched_by' => $fresh->dispatchedByRef? ['id'=>$fresh->dispatchedByRef->id,'name'=>$fresh->dispatchedByRef->name,'username'=>$fresh->dispatchedByRef->username] : null,
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code'    => 422,
                'status'  => false,
                'message' => 'Validation error!',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Order update failed', [
                'order_id' => $id,
                'error'    => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while updating order.',
            ], 500);
        }
    }

    // delete
    public function delete(Request $request, $id)
    {
        try {
            // 1) Load with related objects for a good snapshot
            $o = OrdersModel::with([
                    'clientRef:id,name',
                    'contactRef:id,client,name,rm,mobile,email',
                    'initiatedByRef:id,name,username',
                    'checkedByRef:id,name,username',
                    'dispatchedByRef:id,name,username',
                ])
                ->select(
                    'id','company','client','client_contact_person',
                    'so_no','so_date','order_no','order_date',
                    'invoice','status',
                    'initiated_by','checked_by','dispatched_by',
                    'drive_link','created_at','updated_at'
                )
                ->find($id);

            if (! $o) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Order not found.',
                ], 404);
            }

            // 2) Build snapshot BEFORE delete
            $snapshot = [
                'id'         => $o->id,
                'company'    => $o->company,
                'so_no'      => $o->so_no,
                'so_date'    => $o->so_date,
                'order_no'   => $o->order_no,
                'order_date' => $o->order_date,
                'status'     => $o->status,
                'client'     => $o->clientRef
                    ? ['id'=>$o->clientRef->id, 'name'=>$o->clientRef->name] : null,
                'client_contact_person' => $o->contactRef
                    ? [
                        'id' => $o->contactRef->id,
                        'name' => $o->contactRef->name,
                        'designation' => $o->contactRef->rm,
                        'mobile' => $o->contactRef->mobile,
                        'email' => $o->contactRef->email,
                    ] : null,
                'email' => $o->email,
                'mobile' => $o->mobile,
                'invoice' => $o->invoice ? ['id' => (int) $o->invoice] : null,
                'initiated_by'  => $o->initiatedByRef ? [
                    'id'=>$o->initiatedByRef->id, 'name'=>$o->initiatedByRef->name, 'username'=>$o->initiatedByRef->username
                ] : null,
                'checked_by'    => $o->checkedByRef ? [
                    'id'=>$o->checkedByRef->id, 'name'=>$o->checkedByRef->name, 'username'=>$o->checkedByRef->username
                ] : null,
                'dispatched_by' => $o->dispatchedByRef ? [
                    'id'=>$o->dispatchedByRef->id, 'name'=>$o->dispatchedByRef->name, 'username'=>$o->dispatchedByRef->username
                ] : null,
                'drive_link' => $o->drive_link,
                'created_at' => $o->created_at,
                'updated_at' => $o->updated_at,
            ];

            // 3) Delete (wrap in transaction for safety)
            DB::transaction(function () use ($o) {
                $o->delete();
            });

            // 4) Respond
            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'Order deleted successfully!',
                'data'    => $snapshot,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Order delete failed', [
                'order_id' => $id,
                'error'    => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);
            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while deleting order.',
            ], 500);
        }
    }
    
    /* ------------------------------------------------------------------
     | 1.  Allowed next statuses
     * ------------------------------------------------------------------*/
    /**
     * Get the list of statuses an order is allowed to move to.
     *
     * @param Request $request
     * @param int     $id   Order ID
     * @return JsonResponse
     */
    public function validate_order_status(int $id): JsonResponse
    {
        try {
            /* ----------------------------------------------------------
             * 1.  Find the order
             * ---------------------------------------------------------- */
            $order = OrdersModel::find($id);

            if (!$order) {
                return response()->json([
                    'code'    => 404,
                    'status'  => false,
                    'message' => 'Order not found.',
                ], 404);
            }

            /* ----------------------------------------------------------
             * 2.  Allowed transitions map
             * ---------------------------------------------------------- */
            $transitions = [
                'pending'         => ['dispatched'],
                'dispatched'      => ['completed', 'partial_pending', 'out_of_stock'],
                'completed'       => ['invoiced', 'cancelled'],
                'partial_pending' => ['dispatched', 'short_closed', 'cancelled'],
                'out_of_stock'    => ['pending', 'cancelled'],
                'short_closed'    => ['invoiced', 'cancelled'],

                /* ------------------------------------------------------
                 * Terminal statuses – no further moves
                 * ------------------------------------------------------ */
                'invoiced'        => [],
                'cancelled'       => [],
            ];

            /* ----------------------------------------------------------
             * 3.  Return the list
             * ---------------------------------------------------------- */
            $allowed = $transitions[$order->status] ?? [];

            return response()->json([
                'code'   => 200,
                'status' => true,
                'data'   => [
                    'current_status' => $order->status,
                    'allowed_status' => $allowed,
                ],
            ], 200);
        } catch (\Throwable $e) {
            \Log::error('get_order_status failed', [
                'order_id' => $id,
                'error'    => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while retrieving allowed statuses.',
            ], 500);
        }
    }

    /* ------------------------------------------------------------------
     | 2.  Perform the status change
     * ------------------------------------------------------------------*/
    // public function updateStatus(Request $request): JsonResponse
    // {
    //     $rules = [
    //         'order_id'          => 'required|string|exists:t_orders,order_no',
    //         'status'            => 'required|string|in:dispatched,invoiced,completed,partial_pending,out_of_stock,short_closed,cancelled',
    //         'optional_fields'   => 'nullable|array',
    //     ];
    //     $validated = $request->validate($rules);

    //     DB::beginTransaction();
    //     try {
    //         $order = OrdersModel::where('order_no', $validated['order_id'])->firstOrFail();

    //         /* ----------------------------------------------------------
    //          * A.  Is the requested move allowed ?
    //          * ---------------------------------------------------------- */
    //         $allowed = $this->getAllowedNextStatuses($order->status);
    //         if (!in_array($validated['status'], $allowed, true)) {
    //             return response()->json([
    //                 'code'    => 422,
    //                 'status'  => false,
    //                 'message' => "Invalid transition from {$order->status} to {$validated['status']}.",
    //             ], 422);
    //         }

    //         $user = auth()->user(); // via sanctum / passport / whatever you use

    //         /* ----------------------------------------------------------
    //          * B.  Status-specific checks & data preparation
    //          * ---------------------------------------------------------- */
    //         $extra = [];

    //         switch ($validated['status']) {
    //             case 'dispatched':
    //                 $dispatchedBy = $validated['optional_fields']['dispatched_by'] ?? null;
    //                 if (!$dispatchedBy) {
    //                     throw new \Exception('dispatched_by user id is required.');
    //                 }
                    
    //                 // save who is triggering the dispatch
    //                 $extra['initiated_by'] = auth()->id();   // <-- from token
    //                 $extra['dispatched_by'] = $dispatchedBy; // <-- from request
    //                 //  set dispatched_date to today
    //                 $extra['dispatched_date'] = now()->toDateString(); // YYYY-MM-DD
    //                 break;

    //             case 'invoiced':
    //                 $invNum = $validated['optional_fields']['invoice_number'] ?? null;
    //                 $invDate = $validated['optional_fields']['invoice_date'] ?? null;
    //                 if (!$invNum || !$invDate) {
    //                     throw new \Exception('invoice_number and invoice_date are required for invoicing.');
    //                 }
    //                 // who is creating the invoice = bearer token
    //                 $billedBy = auth()->id();

    //                 // create invoice record
    //                 $invoice = app(InvoiceController::class)
    //                             ->makeInvoice([
    //                                 'order'          => $order->id,
    //                                 'invoice_number' => $invNum,
    //                                 'invoice_date'   => $invDate,
    //                                 'billed_by'      => $user->id,
    //                             ]);

    //                 $extra['invoice'] = $invoice->id;
    //                 break;
    //         }

    //         /* ----------------------------------------------------------
    //          * C.  Update order
    //          * ---------------------------------------------------------- */
    //         $order->status = $validated['status'];
    //         foreach ($extra as $k => $v) {
    //             $order->$k = $v;
    //         }
    //         $order->save();

    //         DB::commit();

    //         return response()->json([
    //             'code'    => 200,
    //             'status'  => true,
    //             'message' => 'Order status updated successfully.',
    //             'data'    => [
    //                 'order_id' => $order->id,
    //                 'status'   => $order->status,
    //                 'dispatched_date' => $order->dispatched_date,
    //             ],
    //         ], 200);
    //     } catch (\Throwable $e) {
    //         DB::rollBack();
    //         \Log::error('changeStatus failed', [
    //             'order_id' => $validated['order_id'] ?? 'unknown',
    //             'payload'  => $request->all(),
    //             'error'    => $e->getMessage(),
    //         ]);

    //         return response()->json([
    //             'code'    => 500,
    //             'status'  => false,
    //             'message' => $e->getMessage() ?: 'Status update failed.',
    //         ], 500);
    //     }
    // }

    public function updateStatus(Request $request): JsonResponse
    {
        $rules = [
            'order_id'        => 'required|string|exists:t_orders,order_no',
            'status'          => 'required|string|in:dispatched,invoiced,completed,partial_pending,out_of_stock,short_closed,cancelled',
            'optional_fields' => 'nullable|array',

            // if you want to enforce dispatched_by presence & validity when used:
            'optional_fields.dispatched_by'   => 'nullable|integer|exists:users,id',
            // if later you also support checked_by via this endpoint:
            // 'optional_fields.checked_by'   => 'nullable|integer|exists:users,id',
        ];

        $validated = $request->validate($rules);

        DB::beginTransaction();
        try {
            $order = OrdersModel::where('order_no', $validated['order_id'])->firstOrFail();

            /* ----------------------------------------------------------
            * A.  Is the requested move allowed ?
            * ---------------------------------------------------------- */
            $allowed = $this->getAllowedNextStatuses($order->status);
            if (!in_array($validated['status'], $allowed, true)) {
                return response()->json([
                    'code'    => 422,
                    'status'  => false,
                    'message' => "Invalid transition from {$order->status} to {$validated['status']}.",
                ], 422);
            }

            $user  = auth()->user(); // via sanctum / passport / whatever you use
            $extra = [];

            /* ----------------------------------------------------------
            * B.  Status-specific checks & data preparation
            * ---------------------------------------------------------- */
            switch ($validated['status']) {
                case 'dispatched':
                    $dispatchedBy = $validated['optional_fields']['dispatched_by'] ?? null;
                    if (!$dispatchedBy) {
                        throw new \Exception('dispatched_by user id is required.');
                    }

                    // 🔥 ensure dispatched_by is staff
                    $dispatchedUser = User::find($dispatchedBy);
                    // if (!$dispatchedUser || $dispatchedUser->role !== 'staff') {
                    //     throw new \Exception('dispatched_by user must be a valid staff user.');
                    // }

                    // (optional) if you also want checked_by here:
                    /*
                    $checkedBy = $validated['optional_fields']['checked_by'] ?? null;
                    if ($checkedBy) {
                        $checkedUser = User::find($checkedBy);
                        if (!$checkedUser || $checkedUser->role !== 'staff') {
                            throw new \Exception('checked_by user must be a valid staff user.');
                        }
                        $extra['checked_by'] = $checkedBy;
                    }
                    */

                    // who is triggering the dispatch (from token)
                    $extra['initiated_by']    = $user->id;
                    // who is marked as dispatching (from request)
                    $extra['dispatched_by']   = $dispatchedBy;
                    // 🔥 set dispatched_date to today
                    $extra['dispatched_date'] = now()->toDateString(); // YYYY-MM-DD
                    break;

                case 'invoiced':
                    $invNum  = $validated['optional_fields']['invoice_number'] ?? null;
                    $invDate = $validated['optional_fields']['invoice_date'] ?? null;
                    if (!$invNum || !$invDate) {
                        throw new \Exception('invoice_number and invoice_date are required for invoicing.');
                    }

                    // who is creating the invoice = bearer token
                    $invoice = app(InvoiceController::class)
                        ->makeInvoice([
                            'order'          => $order->id,
                            'invoice_number' => $invNum,
                            'invoice_date'   => $invDate,
                            'billed_by'      => $user->id,
                        ]);

                    $extra['invoice'] = $invoice->id;
                    break;
            }

            /* ----------------------------------------------------------
            * C.  Update order
            * ---------------------------------------------------------- */
            $order->status = $validated['status'];
            foreach ($extra as $k => $v) {
                $order->$k = $v;
            }
            $order->save();

            DB::commit();

            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'Order status updated successfully.',
                'data'    => [
                    'order_id'        => $order->id,
                    'status'          => $order->status,
                    'dispatched_date' => $order->dispatched_date,
                ],
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('changeStatus failed', [
                'order_id' => $validated['order_id'] ?? 'unknown',
                'payload'  => $request->all(),
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => $e->getMessage() ?: 'Status update failed.',
            ], 500);
        }
    }

    /* ------------------------------------------------------------------
     | Helper: allowed next statuses
     * ------------------------------------------------------------------*/
    private function getAllowedNextStatuses(string $current): array
    {
        return [
            'pending'         => ['dispatched'],
            'dispatched'      => ['completed','partial_pending','out_of_stock'],
            'completed'       => ['cancelled'],
            'partial_pending' => ['dispatched','short_closed','cancelled'],
            'out_of_stock'    => ['dispatched','cancelled'],
            'short_closed'    => ['invoiced','cancelled'],
            'invoiced'        => [],
            'cancelled'       => [],
        ][$current] ?? [];
    }

    public function orderStatusCounts(Request $request): JsonResponse
    {
        try {
            $dateFrom = $request->input('date_from', $request->input('start_date')); // YYYY-MM-DD
            $dateTo   = $request->input('date_to', $request->input('end_date'));     // YYYY-MM-DD

            $baseQuery = OrdersModel::query();
            if (!empty($dateFrom)) {
                $baseQuery->whereDate('order_date', '>=', $dateFrom);
            }
            if (!empty($dateTo)) {
                $baseQuery->whereDate('order_date', '<=', $dateTo);
            }

            $total = (clone $baseQuery)->count();

            $statusCounts = $baseQuery
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get()
                ->pluck('count', 'status');

            $preferredOrder = [
                'pending',
                'dispatched',
                'completed',
                'invoiced',
                'partial_pending',
                'short_closed',
                'cancelled',
                'out_of_stock',
            ];

            $items = [];
            $items[] = ['type' => 'Total', 'count' => (int) $total];

            foreach ($preferredOrder as $status) {
                $items[] = [
                    'type'  => $status,
                    'count' => (int) ($statusCounts[$status] ?? 0),
                ];
            }

            $remainingStatuses = array_diff($statusCounts->keys()->all(), $preferredOrder);
            sort($remainingStatuses);
            foreach ($remainingStatuses as $status) {
                $items[] = ['type' => $status, 'count' => (int) $statusCounts[$status]];
            }

            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'Order status counts retrieved.',
                'data'    => $items,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Order status counts failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while fetching order status counts.',
            ], 500);
        }
    }

    // export order
    // public function exportCsv(Request $request)
    // {
    //     try {
    //         // Optional filters (same as before)
    //         $search       = trim((string) $request->input('search', ''));
    //         $clientId     = $request->input('client');
    //         $status       = $request->input('status');
    //         $checkedBy    = $request->input('checked_by');
    //         $dispatchedBy = $request->input('dispatched_by');
    //         $dateFrom     = $request->input('date_from');
    //         $dateTo       = $request->input('date_to');

    //         // Fetch data
    //         $q = DB::table('t_orders as o')
    //             ->leftJoin('t_clients as c', 'c.id', '=', 'o.client')
    //             ->leftJoin('t_clients_contact_person as cp', 'cp.id', '=', 'o.client_contact_person')
    //             ->leftJoin('users as u_checked', 'u_checked.id', '=', 'o.checked_by')
    //             ->leftJoin('users as u_disp', 'u_disp.id', '=', 'o.dispatched_by')
    //             ->leftJoin('t_invoice as inv', 'inv.id', '=', 'o.invoice')
    //             ->selectRaw("
    //                 c.name               as client_name,
    //                 cp.name              as contact_name,
    //                 o.so_no              as so_number,
    //                 o.order_no           as order_number,
    //                 o.order_date         as order_date,
    //                 u_checked.name       as checked_by_name,
    //                 o.status             as status,
    //                 inv.invoice_number   as invoice_number,
    //                 inv.invoice_date     as invoice_date,
    //                 u_disp.name          as dispatched_by_name,
    //                 o.drive_link         as drive_link
    //             ")
    //             ->orderBy('o.id','desc');

    //         // Apply filters
    //         if ($search !== '') {
    //             $q->where(function ($w) use ($search) {
    //                 $w->where('o.so_no', 'like', "%{$search}%")
    //                   ->orWhere('o.order_no', 'like', "%{$search}%");
    //             });
    //         }
    //         if (!empty($clientId)) {
    //             $q->where('o.client', (int) $clientId);
    //         }
    //         if (!empty($status)) {
    //             $q->where('o.status', $status);
    //         }
    //         if (!empty($checkedBy)) {
    //             $q->where('o.checked_by', (int) $checkedBy);
    //         }
    //         if (!empty($dispatchedBy)) {
    //             $q->where('o.dispatched_by', (int) $dispatchedBy);
    //         }
    //         if (!empty($dateFrom)) {
    //             $q->whereDate('o.order_date', '>=', $dateFrom);
    //         }
    //         if (!empty($dateTo)) {
    //             $q->whereDate('o.order_date', '<=', $dateTo);
    //         }

    //         $rows = $q->get();

    //         // Ensure folder exists
    //         $directory = 'uploads/order';
    //         if (!Storage::disk('public')->exists($directory)) {
    //             Storage::disk('public')->makeDirectory($directory);
    //         }

    //         // Filename
    //         $filename = 'orders_export_' . now()->format('Ymd_His') . '.csv';
    //         $fullPath = $directory . '/' . $filename;

    //         // Create and save CSV file
    //         $handle = fopen(storage_path('app/public/' . $fullPath), 'w');

    //         // Headings
    //         fputcsv($handle, [
    //             'CLIENT',
    //             'CLIENT CONTACT PERSON',
    //             'SO NUMBER',
    //             'ORDER NUMBER',
    //             'ORDER DATE',
    //             'CHECKED BY',
    //             'STATUS',
    //             'INVOICE NUMBER',
    //             'INVOICE DATE',
    //             'DISPATCHED BY',
    //             'DRIVE LINK',
    //         ]);

    //         // Rows
    //         foreach ($rows as $r) {
    //             fputcsv($handle, [
    //                 $r->client_name,
    //                 $r->contact_name,
    //                 $r->so_number,
    //                 $r->order_number,
    //                 $r->order_date,
    //                 $r->checked_by_name,
    //                 $r->status,
    //                 $r->invoice_number,
    //                 $r->invoice_date,
    //                 $r->dispatched_by_name,
    //                 $r->drive_link,
    //             ]);
    //         }

    //         fclose($handle);

    //         // Public URL
    //         $publicUrl = url('storage/' . $fullPath);

    //         return response()->json([
    //             'code'    => 200,
    //             'status'  => true,
    //             'message' => 'Orders exported successfully.',
    //             'file_url'=> $publicUrl,
    //         ], 200);

    //     } catch (\Throwable $e) {
    //         Log::error('Orders export failed', [
    //             'error' => $e->getMessage(),
    //             'file'  => $e->getFile(),
    //             'line'  => $e->getLine(),
    //         ]);

    //         return response()->json([
    //             'code'    => 500,
    //             'status'  => false,
    //             'message' => 'Something went wrong while exporting orders.',
    //         ], 500);
    //     }
    // }

    public function exportExcel(Request $request)
    {
        try {
            $search       = trim((string) $request->input('search', ''));
            $clientId     = $request->input('client');
            $status       = $request->input('status');
            $checkedBy    = $request->input('checked_by');
            $dispatchedBy = $request->input('dispatched_by');
            $dateFrom     = $request->input('date_from');
            $dateTo       = $request->input('date_to');

            $q = DB::table('t_orders as o')
                ->leftJoin('t_clients as c', 'c.id', '=', 'o.client')
                ->leftJoin('t_clients_contact_person as cp', 'cp.id', '=', 'o.client_contact_person')
                ->leftJoin('users as u_checked', 'u_checked.id', '=', 'o.checked_by')
                ->leftJoin('users as u_disp', 'u_disp.id', '=', 'o.dispatched_by')
                ->leftJoin('t_invoice as inv', 'inv.id', '=', 'o.invoice')
                ->selectRaw("
                    c.name               as client_name,
                    cp.name              as contact_name,
                    o.so_no              as so_number,
                    o.order_no           as order_number,
                    o.order_date         as order_date,
                    u_checked.name       as checked_by_name,
                    o.status             as status,
                    inv.invoice_number   as invoice_number,
                    inv.invoice_date     as invoice_date,
                    u_disp.name          as dispatched_by_name,
                    o.drive_link         as drive_link
                ")
                ->orderBy('o.id','desc');

            if ($search !== '') {
                $q->where(function ($w) use ($search) {
                    $w->where('o.so_no', 'like', "%{$search}%")
                      ->orWhere('o.order_no', 'like', "%{$search}%");
                });
            }
            if (!empty($clientId)) $q->where('o.client', (int)$clientId);
            if (!empty($status)) $q->where('o.status', $status);
            if (!empty($checkedBy)) $q->where('o.checked_by', (int)$checkedBy);
            if (!empty($dispatchedBy)) $q->where('o.dispatched_by', (int)$dispatchedBy);
            if (!empty($dateFrom)) $q->whereDate('o.order_date', '>=', $dateFrom);
            if (!empty($dateTo)) $q->whereDate('o.order_date', '<=', $dateTo);

            $rows = $q->get();

            // Ensure folder exists
            $directory = 'uploads/order';
            if (!Storage::disk('public')->exists($directory)) {
                Storage::disk('public')->makeDirectory($directory);
            }

            // Create Excel file
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Headings in camelCase
            $headings = [
                'Sl No',
                'Client',
                'Client Contact Person',
                'SO Number',
                'Order Number',
                'Order Date',
                'Checked By',
                'Status',
                'Invoice Number',
                'Invoice Date',
                'Dispatched By',
                'Drive Link',
            ];

            // Define header style (this was missing)
            $headerStyle = [
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
            ];

            $rowBorderStyle = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['argb' => '000000'],
                    ],
                ],
            ];

            $sheet->fromArray($headings, null, 'A1');
            $sheet->getStyle('A1:L1')->applyFromArray($headerStyle);

            // ---------- DATA ROWS ----------
            $rowNum = 2;
            $sl     = 1;                                 // running serial
            foreach ($rows as $r) {
                $sheet->fromArray([
                    $sl,
                    $r->client_name,
                    $r->contact_name,
                    $r->so_number,
                    $r->order_number,
                    \Carbon\Carbon::parse($r->order_date)->format('d-m-Y'),
                    $r->checked_by_name,
                    $r->status,
                    $r->invoice_number,
                    $r->invoice_date ? \Carbon\Carbon::parse($r->invoice_date)->format('d-m-Y') : '',
                    $r->dispatched_by_name,
                    $r->drive_link,
                ], null, 'A' . $rowNum);

            /* =========  NEW CODE STARTS HERE  ========= */
            $status = strtolower(trim($r->status));   // snake_case from DB
            $cell   = "H{$rowNum}";

            /* map snake_case -> pretty label */
            $labelMap = [
                'pending'         => 'Pending',
                'dispatched'      => 'Dispatched',
                'completed'       => 'Completed',
                'partial_pending' => 'Partial Pending',
                'out_of_stock'    => 'Out of Stock',
                'short_closed'    => 'Short Closed',
                'invoiced'        => 'Invoiced',
                'cancelled'       => 'Cancelled',
            ];

            /* map pretty label -> hex background (black text for all) */
            $bgMap = [
                'Pending'         => 'FFE5E7EB',   // add FF prefix for ARGB
                'Dispatched'      => 'FFFEF3C7',
                'Completed'       => 'FFD1FAE5',
                'Partial Pending' => 'FFFCE7F3',
                'Out of Stock'    => 'FFFEE2E2',
                'Short Closed'    => 'FFEFF6FF',
                'Invoiced'        => 'FF15905F',
                'Cancelled'       => 'FFFEE2E2',
            ];

            $prettyLabel = $labelMap[$status] ?? ucfirst($status);
            $bgARGB      = $bgMap[$prettyLabel] ?? 'FFFFFFFF';   // fallback white

            /* write the pretty label into the cell */
            $sheet->setCellValue($cell, $prettyLabel);

            /* apply background + black font */
            $sheet->getStyle($cell)->applyFromArray([
                'fill' => [
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => $bgARGB],
                ],
                'font' => [
                    'color' => ['argb' => $prettyLabel === 'Invoiced' ? 'FFFFFFFF' : 'FF000000'],
                ],
            ]);
            /* =========  NEW CODE ENDS HERE  ========= */
            // existing border-style block (leave untouched)
            $sheet->getStyle("A{$rowNum}:L{$rowNum}")->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['argb' => '000000'],
                    ],
                ],
            ]);

            $rowNum++;
            $sl++;
        }
            // ---------- AUTO-SIZE ----------
            foreach (range('A', 'L') as $col) {          // L instead of K
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Save file
            $filename = 'orders_export_' . now()->format('Ymd_His') . '.xlsx';
            $directory = 'uploads/order';

            if (!Storage::disk('public')->exists($directory)) {
                Storage::disk('public')->makeDirectory($directory);
            }

            $path = storage_path("app/public/{$directory}/{$filename}");
            $writer = new Xlsx($spreadsheet);
            $writer->save($path);

            // Public URL using Laravel's disk
            $publicUrl = Storage::disk('public')->url("{$directory}/{$filename}");

            return response()->json([
                'code'    => 200,
                'success' => true,
                'message' => 'Orders exported successfully.',
                'data'    => [
                    'file_url' => $publicUrl,
                    'count'    => $rows->count(),
                ],
            ], 200);


        } catch (\Throwable $e) {
            Log::error('Orders Excel export failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

           return response()->json([
                'code'    => 500,
                'success' => false,
                'message' => 'Something went wrong while exporting Excel.',
                'data'    => [],
            ], 500);
        }
    }

    // public function getNextSoNumber(Request $request): JsonResponse
    // {
    //     try {
    //         // Validate the input
    //         $validated = $request->validate([
    //             'company' => ['required', 'string', 'max:10'],   // SHHT / SHAPL
    //         ]);

    //         $company = strtoupper(trim($validated['company']));

    //         // Current year → FY postfix
    //         $dt  = now();
    //         $yy  = (int)$dt->format('y');   // 25
    //         $yy2 = $yy + 1;                 // 26
    //         $postfix = sprintf('%02d-%02d', $yy, $yy2);

    //         // Generate SO number atomically
    //         $soNo = DB::transaction(function () use ($company, $postfix) {

    //             // Fetch counter row for this company
    //             $row = CounterModel::where('prefix', $company)
    //                 ->lockForUpdate()
    //                 ->first();

    //             if (!$row) {
    //                 // First record for this company
    //                 $row = CounterModel::create([
    //                     'prefix'  => $company,
    //                     'number'  => 1,
    //                     'postfix' => $postfix,
    //                 ]);
    //             } else {
    //                 // If FY changed → update postfix
    //                 if ($row->postfix !== $postfix) {
    //                     $row->postfix = $postfix;
    //                 }
    //                 $row->number++;
    //                 $row->save();
    //             }

    //             // Build final SO No: SHHT/001/25-26
    //             return sprintf('%s/%03d/%s', $row->prefix, $row->number, $row->postfix);
    //         });

    //         return response()->json([
    //             'code'    => 200,
    //             'success' => true,
    //             'message' => 'SO number generated successfully.',
    //             'data'    => [ 'so_no' => $soNo ],
    //         ], 200);

    //     } catch (\Throwable $e) {
    //         return response()->json([
    //             'code'    => 500,
    //             'success' => false,
    //             'message' => 'Failed to generate SO number.',
    //             'data'    => [],
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    // public function getNextSoNumber(Request $request): JsonResponse
    // {
    //     try {
    //         $validated = $request->validate([
    //             'company' => ['required', 'string', 'max:10'],   // SHHT / SHAPL
    //         ]);

    //         $company = strtoupper(trim($validated['company']));

    //         // FY postfix
    //         $dt  = now();
    //         $yy  = (int)$dt->format('y');
    //         $yy2 = $yy + 1;
    //         $postfix = sprintf('%02d-%02d', $yy, $yy2);

    //         // ✅ DO NOT lockForUpdate here, DO NOT increment here
    //         $row = CounterModel::where('prefix', $company)->first();

    //         // if not found, show first number as 001
    //         $nextNumber = $row ? (int)$row->number : 1;

    //         // if FY changed, show new postfix
    //         $usePostfix = $row ? ($row->postfix !== $postfix ? $postfix : $row->postfix) : $postfix;

    //         $soNo = sprintf('%s/%03d/%s', $company, $nextNumber, $usePostfix);

    //         return response()->json([
    //             'code'    => 200,
    //             'success' => true,
    //             'message' => 'SO number fetched successfully.',
    //             'data'    => [
    //                 'so_no'   => $soNo,
    //                 'prefix'  => $company,
    //                 'number'  => $nextNumber,
    //                 'postfix' => $usePostfix,
    //             ],
    //         ], 200);

    //     } catch (\Throwable $e) {
    //         return response()->json([
    //             'code'    => 500,
    //             'success' => false,
    //             'message' => 'Failed to fetch SO number.',
    //             'data'    => [],
    //         ], 500);
    //     }
    // }
    public function getNextSoNumber(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'company' => ['required', 'string', 'max:10'],   // SHHT / SHAPL
            ]);

            $company = strtoupper(trim($validated['company']));

            // ✅ DO NOT lock and DO NOT increment (preview only)
            $row = CounterModel::where('prefix', $company)->first();

            $nextNumber = $row ? (int)$row->number : 1;
            $postfix    = $row ? trim((string)$row->postfix) : '';

            $soNo = $postfix !== ''
                ? sprintf('%s/%03d/%s', $company, $nextNumber, $postfix)
                : sprintf('%s/%03d', $company, $nextNumber);

            return response()->json([
                'code'    => 200,
                'success' => true,
                'message' => 'SO number fetched successfully.',
                'data'    => [
                    'so_no'   => $soNo,
                    'prefix'  => $company,
                    'number'  => $nextNumber,
                    'postfix' => $postfix,
                ],
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'code'    => 500,
                'success' => false,
                'message' => 'Failed to fetch SO number.',
                'data'    => [],
            ], 500);
        }
    }
}
