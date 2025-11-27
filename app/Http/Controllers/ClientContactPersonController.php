<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\ClientsContactPersonModel;
use App\Models\User;
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
                'contacts.*.rm'          => ['required', 'integer', 'exists:users,id'], // 🔥 replaced designation
                'contacts.*.mobile'      => ['nullable', 'string', 'max:20'],
                'contacts.*.email'       => ['nullable', 'email', 'max:255'],
            ]);

            $clientId = (int) $validated['client'];
            $contacts = $validated['contacts'];

            // 2️⃣ Validate RM user roles = staff
            foreach ($contacts as $idx => $c) {
                $rmUser = User::find($c['rm']);

                if (!$rmUser || $rmUser->role !== 'staff') {
                    return response()->json([
                        'code'   => 422,
                        'status' => false,
                        'message'=> "Invalid RM at index {$idx}. RM must be a valid staff user.",
                    ], 422);
                }
            }

            // 3️⃣ Extra rule: at least mobile OR email required
            foreach ($contacts as $idx => $c) {
                $hasMobile = !empty($c['mobile'] ?? null);
                $hasEmail  = !empty($c['email'] ?? null);

                if (!$hasMobile && !$hasEmail) {
                    return response()->json([
                        'code'   => 422,
                        'status' => false,
                        'message'=> "At least mobile or email is required for contact index {$idx}.",
                    ], 422);
                }
            }

            // 4️⃣ Validate that existing IDs belong to this client
            $idsProvided = collect($contacts)
                ->pluck('id')
                ->filter()
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
                        'message'=> 'Some contact IDs do not belong to this client.',
                        'errors' => ['invalid_contact_ids' => $invalid->values()],
                    ], 422);
                }
            }

            // 5️⃣ Transaction: UPSERT + DELETE missing
            $finalContacts = DB::transaction(function () use ($clientId, $contacts) {

                $existingIds = ClientsContactPersonModel::where('client', $clientId)
                    ->pluck('id')
                    ->toArray();

                $keepIds = [];

                foreach ($contacts as $c) {
                    $id     = $c['id'] ?? null;
                    $name   = $c['name'];
                    $rm     = $c['rm'];
                    $mobile = $c['mobile'] ?? null;
                    $email  = $c['email'] ?? null;

                    if (!empty($id)) {
                        // UPDATE
                        $contact = ClientsContactPersonModel::where('client', $clientId)->find($id);
                        $contact->update([
                            'name'        => $name,
                            'rm'          => $rm,          // 🔥 updated
                            'mobile'      => $mobile,
                            'email'       => $email,
                        ]);
                    } else {
                        // INSERT
                        $contact = ClientsContactPersonModel::create([
                            'client'      => $clientId,
                            'name'        => $name,
                            'rm'          => $rm,          // 🔥 updated
                            'mobile'      => $mobile,
                            'email'       => $email,
                        ]);
                    }

                    $keepIds[] = $contact->id;
                }

                // DELETE contacts not in new list
                $toDelete = array_diff($existingIds, $keepIds);

                if (!empty($toDelete)) {
                    ClientsContactPersonModel::where('client', $clientId)
                        ->whereIn('id', $toDelete)
                        ->delete();
                }

                return ClientsContactPersonModel::where('client', $clientId)
                    ->orderBy('id')
                    ->get();
            });

            // 6️⃣ Success response
            return response()->json([
                'code'    => 201,
                'status'  => true,
                'message' => 'Contact persons synced successfully!',
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
            Log::error('Contact sync failed', [
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
                $cp = ClientsContactPersonModel::with(['clientRef:id,name', 'rmUser:id,name,username,email'])
                    ->select('id', 'client', 'name', 'rm', 'mobile', 'email')
                    ->find($id);

                if (! $cp) {
                    return response()->json([
                        'code'    => 404,
                        'status'  => false,
                        'message' => 'Contact person not found.',
                    ], 404);
                }

                $data = [
                    'id'     => $cp->id,
                    'name'   => $cp->name,
                    'rm'     => $cp->rmUser
                        ? ['id' => $cp->rmUser->id, 'name' => $cp->rmUser->name, 'username' => $cp->rmUser->username, 'email' => $cp->rmUser->email]
                        : ($cp->rm ? ['id' => $cp->rm] : null),
                    'mobile' => $cp->mobile,
                    'email'  => $cp->email,
                    'client' => $cp->clientRef
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

            $query = ClientsContactPersonModel::with(['clientRef:id,name', 'rmUser:id,name,username,email'])
                ->select('id', 'client', 'name', 'rm', 'mobile', 'email')
                ->where('client', $clientId)          // 🔴 compulsory filter
                ->orderBy('id', 'desc');

            $items = $query->skip($offset)->take($limit)->get();

            $data = $items->map(function ($cp) {
                return [
                    'id'     => $cp->id,
                    'name'   => $cp->name,
                    'rm'     => $cp->rmUser
                        ? ['id' => $cp->rmUser->id, 'name' => $cp->rmUser->name, 'username' => $cp->rmUser->username, 'email' => $cp->rmUser->email]
                        : ($cp->rm ? ['id' => $cp->rm] : null),
                    'mobile' => $cp->mobile,
                    'email'  => $cp->email,
                    'client' => $cp->clientRef
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
                'client' => ['required','integer','exists:t_clients,id'],
                'name'   => ['required','string','max:255'],

                // rm replaces old designation
                'rm'     => ['sometimes','nullable','integer','exists:users,id'],

                'mobile' => ['nullable','string','max:20'],
                'email'  => ['nullable','email','max:255'],
            ]);

            // 3) NEW RULE: rm must be present (non-null) OR we keep existing cp->rm.
            // If caller wants to explicitly clear RM, they'd send rm=null (not allowed here).
            // We'll enforce that final record has non-null rm value.
            $incomingHasRm = $request->has('rm');
            $finalRm = $incomingHasRm ? $request->input('rm') : $cp->rm;

            if (empty($finalRm)) {
                return response()->json([
                    'code'    => 422,
                    'status'  => false,
                    'message' => 'rm is required and cannot be null. Provide a valid RM user id.',
                ], 422);
            }

            // 4) Ensure the RM user exists and has role 'staff'
            $rmUser = User->select('id','role')->where('id', (int)$finalRm)->first();
            if (! $rmUser) {
                return response()->json([
                    'code'    => 422,
                    'status'  => false,
                    'message' => 'Provided rm user does not exist.',
                ], 422);
            }
            if (! isset($rmUser->role) || $rmUser->role !== 'staff') {
                return response()->json([
                    'code'    => 422,
                    'status'  => false,
                    'message' => 'Provided rm user must have role "staff".',
                ], 422);
            }

            // 5) NEW RULE: at least one of rm / mobile / email must be present in request (or existing).
            $hasMobile = $request->filled('mobile') || !empty($cp->mobile);
            $hasEmail  = $request->filled('email')  || !empty($cp->email);
            $hasRm     = !empty($finalRm);

            if (! $hasRm && ! $hasMobile && ! $hasEmail) {
                return response()->json([
                    'code'    => 422,
                    'status'  => false,
                    'message' => 'Provide at least one of: rm, mobile, or email.',
                ], 422);
            }

            // 6) Column-wise update (keep existing if a field not sent)
            $payload = [
                'client' => (int) $request->input('client'),
                'name'   => $request->input('name'),
                'rm'     => $finalRm,
                'mobile' => $request->has('mobile') ? $request->input('mobile') : $cp->mobile,
                'email'  => $request->has('email') ? $request->input('email') : $cp->email,
            ];

            DB::transaction(function () use ($id, $payload) {
                ClientsContactPersonModel::where('id', $id)->update($payload);
            });

            // 7) Fetch fresh with client and rm objects
            $fresh = ClientsContactPersonModel::with(['clientRef:id,name', 'rmUser:id,name,username,email'])
                ->select('id','client','name','rm','mobile','email')
                ->find($id);

            $data = [
                'id'     => $fresh->id,
                'name'   => $fresh->name,
                'rm'     => $fresh->rmUser
                    ? ['id' => $fresh->rmUser->id, 'name' => $fresh->rmUser->name, 'username' => $fresh->rmUser->username, 'email' => $fresh->rmUser->email]
                    : ($fresh->rm ? ['id' => $fresh->rm] : null),
                'mobile' => $fresh->mobile,
                'email'  => $fresh->email,
                'client' => $fresh->clientRef
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
