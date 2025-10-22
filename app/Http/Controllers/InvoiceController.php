<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Models\InvoiceModel;     // t_invoice
use App\Models\User;
use App\Models\OrderModel;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    //
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

}
