<?php

namespace App\Http\Controllers;
use App\Models\OrderModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
                'company'                => ['required', Rule::in(['SHHT','SHAPN'])],
                'client'                 => ['required','integer','exists:t_clients,id'],
                'client_contact_person'  => ['required','integer','exists:t_clients_contact_person,id'],

                // you asked to provide order_no (unique). We will generate only so_no from counter.
                'order_no'               => ['required','string','max:255','unique:t_orders,order_no'],

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
                return OrderModel::create([
                    'company'               => $request->company,
                    'client'                => (int) $request->client,
                    'client_contact_person' => (int) $request->client_contact_person,

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
                $o = OrderModel::with([
                        'clientRef:id,name',
                        'contactRef:id,client,name,designation,mobile,email',
                        'initiatedByRef:id,name,username',
                        'checkedByRef:id,name,username',
                        'dispatchedByRef:id,name,username',
                        // 'invoiceRef:id,....' // if available
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
                    'invoice' => $o->invoice ? ['id' => (int) $o->invoice] : null, // expand if you have invoiceRef
                    'initiated_by'  => $o->initiatedByRef ? ['id'=>$o->initiatedByRef->id,'name'=>$o->initiatedByRef->name,'username'=>$o->initiatedByRef->username] : null,
                    'checked_by'    => $o->checkedByRef   ? ['id'=>$o->checkedByRef->id,'name'=>$o->checkedByRef->name,'username'=>$o->checkedByRef->username]     : null,
                    'dispatched_by' => $o->dispatchedByRef? ['id'=>$o->dispatchedByRef->id,'name'=>$o->dispatchedByRef->name,'username'=>$o->dispatchedByRef->username] : null,
                    'drive_link'    => $o->drive_link,
                    'created_at'    => $o->created_at,
                    'updated_at'    => $o->updated_at,
                ];

                return response()->json([
                    'status'  => true,
                    'message' => 'Order fetched successfully.',
                    'data'    => $data,
                ], 200);
            }

            // ---------- list with limit/offset + optional filters ----------
            $limit     = (int) $request->input('limit', 10);
            $offset    = (int) $request->input('offset', 0);
            $company   = $request->input('company'); // optional: SHHT / SHAPN
            $status    = $request->input('status');  // optional
            $dateFrom  = $request->input('date_from'); // optional: filter by order_date
            $dateTo    = $request->input('date_to');   // optional

            $q = OrderModel::with([
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

            if (!empty($company)) {
                $q->where('company', strtoupper($company));
            }
            if (!empty($status)) {
                $q->where('status', $status);
            }
            if (!empty($dateFrom)) {
                $q->whereDate('order_date', '>=', $dateFrom);
            }
            if (!empty($dateTo)) {
                $q->whereDate('order_date', '<=', $dateTo);
            }

            $items = $q->skip($offset)->take($limit)->get();

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
                'status'  => true,
                'message' => 'Orders fetched successfully.',
                'count'   => $data->count(),
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

    // update
    public function update(Request $request, $id)
    {
        try {
            // 1️⃣ Find the order
            $order = OrderModel::find($id);
            if (! $order) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Order not found.',
                ], 404);
            }

            // 2️⃣ Validate input
            $request->validate([
                'company'               => ['required', Rule::in(['SHHT', 'SHAPN'])],
                'client'                => ['required', 'integer', 'exists:t_clients,id'],
                'client_contact_person' => ['required', 'integer', 'exists:t_clients_contact_person,id'],
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
                OrderModel::where('id', $id)->update($payload);
            });

            // 4️⃣ Fetch updated record with relations
            $fresh = OrderModel::with([
                'clientRef:id,name',
                'contactRef:id,name,mobile,email,designation',
                'initiatedByRef:id,name,username',
                'checkedByRef:id,name,username',
                'dispatchedByRef:id,name,username',
            ])->find($id);

            return response()->json([
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
                    'initiated_by'  => $fresh->initiatedByRef ? ['id'=>$fresh->initiatedByRef->id,'name'=>$fresh->initiatedByRef->name,'username'=>$fresh->initiatedByRef->username] : null,
                    'checked_by'    => $fresh->checkedByRef   ? ['id'=>$fresh->checkedByRef->id,'name'=>$fresh->checkedByRef->name,'username'=>$fresh->checkedByRef->username] : null,
                    'dispatched_by' => $fresh->dispatchedByRef? ['id'=>$fresh->dispatchedByRef->id,'name'=>$fresh->dispatchedByRef->name,'username'=>$fresh->dispatchedByRef->username] : null,
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
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
            $o = OrderModel::with([
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
                'status'  => false,
                'message' => 'Something went wrong while deleting order.',
            ], 500);
        }
    }
}
