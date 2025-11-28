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
                'code'    => 200,
                'status'  => true,
                'message' => 'Contact persons synced successfully!',
                'data'    => [
                    'client'   => $clientId,
                    'contacts' => $finalContacts,
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

            // 2) Validate core fields (including rm)
            $request->validate([
                'client'      => ['required','integer','exists:t_clients,id'],
                'name'        => ['required','string','max:255'],
                'rm'          => ['nullable','integer','exists:users,id'], // new: rm field
                'mobile'      => ['sometimes','nullable','string','max:20'],
                'email'       => ['sometimes','nullable','email','max:255'],
            ]);

            // 3) Optional: if you want at least one of rm/mobile/email to be present in the request:
            if (
                !$request->filled('rm') &&
                !$request->filled('mobile') &&
                !$request->filled('email')
            ) {
                return response()->json([
                    'code'    => 422,
                    'status'  => false,
                    'message' => 'Provide at least one of: rm, mobile, or email in the request.',
                ], 422);
            }

            // 4) If rm provided, ensure user exists and has role staff (adapt to your role scheme)
            if ($request->filled('rm')) {
                $rmUser = User::find((int)$request->input('rm'));
                if (! $rmUser) {
                    return response()->json([
                        'code' => 422, 'status' => false,
                        'message' => 'RM user not found.',
                    ], 422);
                }

                // change this to match your app's role setup
                if (isset($rmUser->role) && $rmUser->role !== 'staff') {
                    return response()->json([
                        'code' => 422, 'status' => false,
                        'message' => 'RM must be a user with role "staff".',
                    ], 422);
                }
            }

            // 5) Build payload — keep existing values if a field is not present
            $payload = [
                'client' => (int) $request->input('client'),
                'name'   => $request->input('name'),
                'rm'     => $request->has('rm') ? (int)$request->input('rm') : $cp->rm,
                'mobile' => $request->has('mobile') ? $request->input('mobile') : $cp->mobile,
                'email'  => $request->has('email') ? $request->input('email') : $cp->email,
            ];

            DB::transaction(function () use ($id, $payload) {
                ClientsContactPersonModel::where('id', $id)->update($payload);
            });

            // 6) Fetch fresh with client and rm user object
            $fresh = ClientsContactPersonModel::with([
                    'clientRef:id,name',
                    'rmUser:id,name,username,email'
                ])
                ->select('id','client','name','rm','mobile','email') // <-- removed 'designation'
                ->find($id);

            $data = [
                'id'     => $fresh->id,
                'name'   => $fresh->name,
                'rm'     => $fresh->rmUser ? ['id' => $fresh->rmUser->id, 'name' => $fresh->rmUser->name] : null,
                'mobile' => $fresh->mobile,
                'email'  => $fresh->email,
                'client' => $fresh->clientRef ? ['id' => $fresh->clientRef->id, 'name' => $fresh->clientRef->name] : null,
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
