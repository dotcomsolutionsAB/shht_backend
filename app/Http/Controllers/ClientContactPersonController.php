<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\ClientsContactPersonModel;
use Illuminate\Http\Request;

class ClientContactPersonController extends Controller
{
    //
    public function create(Request $request)
    {
        try {
            // 1️⃣ Validate request
            $request->validate([
                'client'      => ['required','integer','exists:t_clients,id'],
                'name'        => ['required','string','max:255'],
                'designation' => ['nullable','string','max:255'],
                'mobile'      => ['nullable','string','max:20'],
                'email'       => ['nullable','email','max:255'],
            ]);

            // Step 2️⃣: Ensure at least one of designation/mobile/email is provided
            if (
                empty($request->input('designation')) &&
                empty($request->input('mobile')) &&
                empty($request->input('email'))
            ) {
                return response()->json([
                    'status'  => false,
                    'message' => 'At least one of designation, mobile, or email must be provided.',
                ], 422);
            }

            // Step 3️⃣: Create inside transaction
            $contact = DB::transaction(function () use ($request) {
                return ClientsContactPersonModel::create([
                    'client'      => (int) $request->input('client'),
                    'name'        => $request->input('name'),
                    'designation' => $request->input('designation'),
                    'mobile'      => $request->input('mobile'),
                    'email'       => $request->input('email'),
                ]);
            });

            // Step 4️⃣: Clean success response
            return response()->json([
                'status'  => true,
                'message' => 'Client contact person created successfully!',
                'data'    => [
                    'id'          => $contact->id,
                    'client'      => $contact->client,
                    'name'        => $contact->name,
                    'designation' => $contact->designation,
                    'mobile'      => $contact->mobile,
                    'email'       => $contact->email,
                ],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation error!',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Contact Person create failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while creating contact person!',
            ], 500);
        }
    }

    // fetch
    public function list(Request $request, $id = null)
    {
        try {
            // ---------- Fetch single by ID ----------
            if ($id !== null) {
                $cp = ClientsContactPersonModel::with(['clientRef:id,name'])
                    ->select('id', 'client', 'name', 'designation', 'mobile', 'email')
                    ->find($id);

                if (! $cp) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Contact person not found.',
                    ], 404);
                }

                $data = [
                    'id'          => $cp->id,
                    'name'        => $cp->name,
                    'designation' => $cp->designation,
                    'mobile'      => $cp->mobile,
                    'email'       => $cp->email,
                    'client'      => $cp->clientRef
                        ? ['id' => $cp->clientRef->id, 'name' => $cp->clientRef->name]
                        : null,
                ];

                return response()->json([
                    'status'  => true,
                    'message' => 'Contact person fetched successfully.',
                    'data'    => $data,
                ], 200);
            }

            // ---------- List with limit/offset (and optional ?client=ID filter) ----------
            $limit  = (int) $request->input('limit', 10);
            $offset = (int) $request->input('offset', 0);
            $clientFilter = $request->input('client'); // optional filter

            $query = ClientsContactPersonModel::with(['clientRef:id,name'])
                ->select('id', 'client', 'name', 'designation', 'mobile', 'email')
                ->orderBy('id', 'desc');

            if ($clientFilter !== null) {
                $query->where('client', (int) $clientFilter);
            }

            $items = $query->skip($offset)->take($limit)->get();

            $data = $items->map(function ($cp) {
                return [
                    'id'          => $cp->id,
                    'name'        => $cp->name,
                    'designation' => $cp->designation,
                    'mobile'      => $cp->mobile,
                    'email'       => $cp->email,
                    'client'      => $cp->clientRef
                        ? ['id' => $cp->clientRef->id, 'name' => $cp->clientRef->name]
                        : null,
                ];
            });

            return response()->json([
                'status'  => true,
                'message' => 'Contact persons fetched successfully.',
                'count'   => $data->count(),
                'data'    => $data,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Contact person fetch failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while fetching contact persons.',
            ], 500);
        }
    }

    // update
    public function update(Request $request, $id)
    {
        try {
            // 1) Find record
            $cp = ClientsContactPersonModel::find($id);
            if (! $cp) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Contact person not found.',
                ], 404);
            }

            // 2) Validate input (client & name required; others optional)
            $request->validate([
                'client'      => ['required','integer','exists:t_clients,id'],
                'name'        => ['required','string','max:255'],
                'designation' => ['nullable','string','max:255'],
                'mobile'      => ['nullable','string','max:20'],
                'email'       => ['nullable','email','max:255'],
            ]);

            // 3) Compute final values (merge provided with existing) to enforce the rule
            $finalDesignation = $request->has('designation') ? $request->input('designation') : $cp->designation;
            $finalMobile      = $request->has('mobile')      ? $request->input('mobile')      : $cp->mobile;
            $finalEmail       = $request->has('email')       ? $request->input('email')       : $cp->email;

            // Require at least one among designation/mobile/email after applying update
            if (empty($finalDesignation) && empty($finalMobile) && empty($finalEmail)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'At least one of designation, mobile, or email must be present.',
                ], 422);
            }

            // 4) Column-wise update (only set what changes)
            $payload = [
                'client'      => (int) $request->input('client'),
                'name'        => $request->input('name'),
                'designation' => $finalDesignation,
                'mobile'      => $finalMobile,
                'email'       => $finalEmail,
            ];

            DB::transaction(function () use ($id, $payload) {
                ClientsContactPersonModel::where('id', $id)->update($payload);
            });

            // 5) Fetch fresh with client object
            $fresh = ClientsContactPersonModel::with(['clientRef:id,name'])
                ->select('id','client','name','designation','mobile','email')
                ->find($id);

            $data = [
                'id'          => $fresh->id,
                'name'        => $fresh->name,
                'designation' => $fresh->designation,
                'mobile'      => $fresh->mobile,
                'email'       => $fresh->email,
                'client'      => $fresh->clientRef
                    ? ['id' => $fresh->clientRef->id, 'name' => $fresh->clientRef->name]
                    : null,
            ];

            return response()->json([
                'status'  => true,
                'message' => 'Contact person updated successfully!',
                'data'    => $data,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation error!',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Contact person update failed', [
                'contact_id' => $id,
                'error'      => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while updating contact person.',
            ], 500);
        }
    }

    // delete
    public function delete(Request $request, $id)
    {
        try {
            // 1️⃣ Fetch the record (with client info)
            $cp = ClientsContactPersonModel::with(['clientRef:id,name'])
                ->select('id','client','name','designation','mobile','email')
                ->find($id);

            if (! $cp) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Contact person not found.',
                ], 404);
            }

            // 2️⃣ Prepare snapshot before deletion
            $snapshot = [
                'id'          => $cp->id,
                'name'        => $cp->name,
                'designation' => $cp->designation,
                'mobile'      => $cp->mobile,
                'email'       => $cp->email,
                'client'      => $cp->clientRef
                    ? ['id' => $cp->clientRef->id, 'name' => $cp->clientRef->name]
                    : null,
            ];

            // 3️⃣ Delete within transaction
            DB::transaction(function () use ($cp) {
                $cp->delete();
            });

            // 4️⃣ Return response
            return response()->json([
                'status'  => true,
                'message' => 'Contact person deleted successfully!',
                'data'    => $snapshot,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Contact person delete failed', [
                'contact_id' => $id,
                'error'      => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while deleting contact person.',
            ], 500);
        }
    }


}
