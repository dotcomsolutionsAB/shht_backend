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
            // 1️⃣ Validate basic structure
            $validated = $request->validate([
                'client'                 => ['required', 'integer', 'exists:t_clients,id'],
                'contacts'               => ['required', 'array', 'min:1'],

                'contacts.*.id'          => ['nullable', 'integer', 'min:1'],
                'contacts.*.name'        => ['required', 'string', 'max:255'],
                'contacts.*.designation' => ['nullable', 'string', 'max:255'],
                'contacts.*.mobile'      => ['nullable', 'string', 'max:20'],
                'contacts.*.email'       => ['nullable', 'email', 'max:255'],
            ]);

            $clientId = (int) $validated['client'];
            $contacts = $validated['contacts'];

            // 2️⃣ Extra rule: for EACH contact, at least one of designation/mobile/email is required
            foreach ($contacts as $idx => $c) {
                $hasDesignation = !empty($c['designation'] ?? null);
                $hasMobile      = !empty($c['mobile'] ?? null);
                $hasEmail       = !empty($c['email'] ?? null);

                if (!$hasDesignation && !$hasMobile && !$hasEmail) {
                    return response()->json([
                        'code'   => 422,
                        'status' => false,
                        'message'=> "At least one of designation, mobile, or email is required for contact index {$idx}.",
                    ], 422);
                }
            }

            // 3️⃣ Validate that all passed IDs (if any) actually belong to this client
            $idsProvided = collect($contacts)
                ->pluck('id')
                ->filter()        // remove null
                ->unique()
                ->values();

            if ($idsProvided->isNotEmpty()) {
                $validIds = ClientsContactPersonModel::where('client', $clientId)
                    ->whereIn('id', $idsProvided)
                    ->pluck('id');

                $invalid = $idsProvided->diff($validIds);

                if ($invalid->isNotEmpty()) {
                    return response()->json([
                        'code'   => 422,
                        'status' => false,
                        'message'=> 'One or more contact IDs do not belong to this client.',
                        'errors' => [
                            'invalid_contact_ids' => $invalid->values(),
                        ],
                    ], 422);
                }
            }

            // 4️⃣ Transaction: upsert + delete missing
            $finalContacts = DB::transaction(function () use ($clientId, $contacts) {
                // existing contacts for this client
                $existingIds = ClientsContactPersonModel::where('client', $clientId)
                    ->pluck('id')
                    ->toArray();

                $keepIds = [];

                foreach ($contacts as $c) {
                    $id          = $c['id'] ?? null;
                    $name        = $c['name'];
                    $designation = $c['designation'] ?? null;
                    $mobile      = $c['mobile'] ?? null;
                    $email       = $c['email'] ?? null;

                    if (!empty($id)) {
                        // UPDATE existing (we already validated that id belongs to client)
                        $contact = ClientsContactPersonModel::where('client', $clientId)->find($id);
                        $contact->update([
                            'name'        => $name,
                            'designation' => $designation,
                            'mobile'      => $mobile,
                            'email'       => $email,
                        ]);
                    } else {
                        // CREATE new
                        $contact = ClientsContactPersonModel::create([
                            'client'      => $clientId,
                            'name'        => $name,
                            'designation' => $designation,
                            'mobile'      => $mobile,
                            'email'       => $email,
                        ]);
                    }

                    $keepIds[] = $contact->id;
                }

                // Delete any old contacts that are not present in the new list
                $idsToDelete = array_diff($existingIds, $keepIds);
                if (!empty($idsToDelete)) {
                    ClientsContactPersonModel::where('client', $clientId)
                        ->whereIn('id', $idsToDelete)
                        ->delete();
                }

                // Return fresh list for this client
                return ClientsContactPersonModel::where('client', $clientId)
                    ->orderBy('id')
                    ->get();
            });

            // 5️⃣ Success response
            return response()->json([
                'code'    => 201,
                'status'  => true,
                'message' => 'Client contact persons synced successfully!',
                'data'    => [
                    'client'   => $clientId,
                    'contacts' => $finalContacts,
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
            Log::error('Contact Person bulk sync failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while syncing contact persons!',
            ], 500);
        }
    }

    // fetch
    public function fetch(Request $request, $id = null)
    {
        try {
            // ---------- Fetch single by ID ----------
            if ($id !== null) {
                $cp = ClientsContactPersonModel::with(['clientRef:id,name'])
                    ->select('id', 'client', 'name', 'designation', 'mobile', 'email')
                    ->find($id);

                if (! $cp) {
                    return response()->json([
                        'code'    => 404,
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
                    'code'    => 200,
                    'status'  => true,
                    'message' => 'Contact person fetched successfully.',
                    'data'    => $data,
                ], 200);
            }

            // ---------- LIST MODE: client is COMPULSORY ----------
            $validated = $request->validate([
                'client' => ['required', 'integer', 'exists:t_clients,id'],
                'limit'  => ['nullable', 'integer', 'min:1'],
                'offset' => ['nullable', 'integer', 'min:0'],
            ]);

            $clientId = (int) $validated['client'];
            $limit    = isset($validated['limit'])  ? (int) $validated['limit']  : 10;
            $offset   = isset($validated['offset']) ? (int) $validated['offset'] : 0;

            $query = ClientsContactPersonModel::with(['clientRef:id,name'])
                ->select('id', 'client', 'name', 'designation', 'mobile', 'email')
                ->where('client', $clientId)          // 🔴 compulsory filter
                ->orderBy('id', 'desc');

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
                'code'    => 200,
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
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while fetching contact persons.',
            ], 500);
        }
    }

    // edit
    public function edit(Request $request, $id)
    {
        try {
            // 1) Find record
            $cp = ClientsContactPersonModel::find($id);
            if (! $cp) {
                return response()->json([
                    'code'    => 404,
                    'status'  => false,
                    'message' => 'Contact person not found.',
                ], 404);
            }

            // 2) Validate core fields
            $request->validate([
                'client'      => ['required','integer','exists:t_clients,id'],
                'name'        => ['required','string','max:255'],
                'designation' => ['nullable','string','max:255'],
                'mobile'      => ['nullable','string','max:20'],
                'email'       => ['nullable','email','max:255'],
            ]);

            // 3) NEW RULE: request MUST include at least one of the three
            if (
                ! $request->filled('designation') &&
                ! $request->filled('mobile') &&
                ! $request->filled('email')
            ) {
                return response()->json([
                    'code'    => 422,
                    'status'  => false,
                    'message' => 'Provide at least one of: designation, mobile, or email in the request.',
                ], 422);
            }

            // 4) Column-wise update (keep existing if a field not sent)
            $payload = [
                'client'      => (int) $request->input('client'),
                'name'        => $request->input('name'),
                'designation' => $request->has('designation') ? $request->input('designation') : $cp->designation,
                'mobile'      => $request->has('mobile')      ? $request->input('mobile')      : $cp->mobile,
                'email'       => $request->has('email')       ? $request->input('email')       : $cp->email,
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
                'code'    => 200,
                'status'  => true,
                'message' => 'Contact person updated successfully!',
                'data'    => $data,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code'    => 422,
                'status'  => false,
                'message' => 'Validation error!',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            \Log::error('Contact person update failed', [
                'contact_id' => $id,
                'error'      => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
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
                    'code'    => 404,
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
                'code'    => 200,
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
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while deleting contact person.',
            ], 500);
        }
    }
}
