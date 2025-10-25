<?php

namespace App\Http\Controllers;
use App\Models\OrdersModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;

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

                'invoice'                => ['nullable','integer','exists:t_invoice,id'],

                'status'                 => ['nullable', Rule::in([
                    'pending','dispatched','partial_pending','invoiced',
                    'completed','short_closed','cancelled','out_of_stock'
                ])],

                'initiated_by'           => ['required','integer','exists:users,id'],
                'checked_by'             => ['required','integer','exists:users,id'],
                'dispatched_by'          => ['required','integer','exists:users,id'],

                'drive_link'             => ['nullable','string','max:255'],
            ]);

            // 2) Create inside a DB transaction (includes counter reservation)
            $order = DB::transaction(function () use ($request) {

                // Reserve counter / generate so_no via CounterController helper
                $counter = $this->counter->getOrIncrementForCompany($request->company);

                // Persist order
                return OrdersModel::create([
                    'company'               => $request->company,
                    'client'                => (int) $request->client,
                    'client_contact_person' => (int) $request->client_contact_person,

                    'email'                 => $request->email,
                    'mobile'                => $request->mobile,

                    'so_no'                 => $counter['so_no'],
                    'so_date'               => $request->so_date,

                    'order_no'              => $request->order_no,
                    'order_date'            => $request->order_date,

                    'invoice'               => $request->invoice, // nullable

                    'status'                => $request->input('status', 'pending'),

                    'initiated_by'          => (int) $request->initiated_by,
                    'checked_by'            => (int) $request->checked_by,
                    'dispatched_by'         => (int) $request->dispatched_by,

                    'drive_link'            => $request->drive_link,
                ]);
            });

            // 3) Success response
            return response()->json([
                'code'    => 201,
                'status'  => true,
                'message' => 'Order created successfully!',
                'data'    => [
                    'id'                    => $order->id,
                    'company'               => $order->company,
                    'so_no'                 => $order->so_no,
                    'so_date'               => $order->so_date,
                    'order_no'              => $order->order_no,
                    'order_date'            => $order->order_date,
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
            ], 201);

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
                        'contactRef:id,client,name,designation,mobile,email',
                        'initiatedByRef:id,name,username',
                        'checkedByRef:id,name,username',
                        'dispatchedByRef:id,name,username',
                        // 'invoiceRef:id,....' // if available
                        'invoiceRef:id,invoice_number,invoice_date',
                    ])
                    ->select(
                        'id','company','client','client_contact_person',
                        'email','mobile',
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

                $data = [
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
                    'contactRef:id,client,name,designation,mobile,email',
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
                    'email'  => $o->email,
                    'mobile' => $o->mobile,
                    'invoice' => $o->invoice ? ['id' => (int) $o->invoice] : null,
                    'initiated_by'  => $o->initiatedByRef ? ['id'=>$o->initiatedByRef->id,'name'=>$o->initiatedByRef->name,'username'=>$o->initiatedByRef->username] : null,
                    'checked_by'    => $o->checkedByRef   ? ['id'=>$o->checkedByRef->id,'name'=>$o->checkedByRef->name,'username'=>$o->checkedByRef->username]     : null,
                    'dispatched_by' => $o->dispatchedByRef? ['id'=>$o->dispatchedByRef->id,'name'=>$o->dispatchedByRef->name,'username'=>$o->dispatchedByRef->username] : null,
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
                'contactRef:id,name,mobile,email,designation',
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
                    'status'             => $fresh->status,
                    'drive_link'         => $fresh->drive_link,
                    'client'             => $fresh->clientRef ? ['id'=>$fresh->clientRef->id, 'name'=>$fresh->clientRef->name] : null,
                    'client_contact_person' => $fresh->contactRef ? [
                        'id' => $fresh->contactRef->id,
                        'name' => $fresh->contactRef->name,
                        'designation' => $fresh->contactRef->designation,
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
                    'contactRef:id,client,name,designation,mobile,email',
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
                        'designation' => $o->contactRef->designation,
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
                'completed'       => ['cancelled'],
                'partial_pending' => ['dispatch', 'short_close', 'cancelled'],
                'out_of_stock'    => ['dispatch', 'cancelled'],
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
                'status'  => false,
                'message' => 'Something went wrong while retrieving allowed statuses.',
            ], 500);
        }
    }

    /* ------------------------------------------------------------------
     | 2.  Perform the status change
     * ------------------------------------------------------------------*/
    public function updateStatus(Request $request): JsonResponse
    {
        $rules = [
            'order_id'          => 'required|string|exists:t_orders,order_no',
            'status'            => 'required|string|in:dispatched,invoiced,completed,partial_pending,out_of_stock,short_closed,cancelled',
            'optional_fields'   => 'nullable|array',
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
                    'status'  => false,
                    'message' => "Invalid transition from {$order->status} to {$validated['status']}.",
                ], 422);
            }

            $user = auth()->user(); // via sanctum / passport / whatever you use

            /* ----------------------------------------------------------
             * B.  Status-specific checks & data preparation
             * ---------------------------------------------------------- */
            $extra = [];

            switch ($validated['status']) {
                case 'dispatched':
                    $dispatchedBy = $validated['optional_fields']['dispatched_by'] ?? null;
                    if (!$dispatchedBy) {
                        throw new \Exception('dispatched_by user id is required.');
                    }
                    
                    // save who is triggering the dispatch
                    $extra['initiated_by'] = auth()->id();   // <-- from token
                    $extra['dispatched_by'] = $dispatchedBy; // <-- from request
                    break;

                case 'invoiced':
                    $invNum = $validated['optional_fields']['invoice_number'] ?? null;
                    $invDate = $validated['optional_fields']['invoice_date'] ?? null;
                    if (!$invNum || !$invDate) {
                        throw new \Exception('invoice_number and invoice_date are required for invoicing.');
                    }
                    // who is creating the invoice = bearer token
                    $billedBy = auth()->id();

                    // create invoice record
                    $invoice = app(InvoiceController::class)
                                ->makeInvoice([
                                    'order'          => $order->id,
                                    'invoice_number' => $invNum,
                                    'invoice_date'   => $invDate,
                                    'billed_by'      => $user->id,
                                ]);

                    $extra['invoice_id'] = $invoice->id;
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
                'status'  => true,
                'message' => 'Order status updated successfully.',
                'data'    => [
                    'order_id' => $order->id,
                    'status'   => $order->status,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('changeStatus failed', [
                'order_id' => $validated['order_id'] ?? 'unknown',
                'payload'  => $request->all(),
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
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

}
