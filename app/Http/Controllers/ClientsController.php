<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Models\ClientsModel;
use App\Models\ClientsContactPersonModel;
use App\Models\TagsModel;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\Request;

class ClientsController extends Controller
{
    // create
    public function create(Request $request)
    {
        try {
            // 1️⃣ Validate main client + nested contact_person
            $request->validate([
                'name'          => ['required','string','max:255'],
                'category'      => ['required','integer','exists:t_category,id'],
                'sub_category'  => ['required','integer','exists:t_sub_category,id'],
                'tags'          => ['nullable','string','max:255'],
                'city'          => ['required','string','max:255'],
                'state'         => ['required','string','max:255'],
                'pincode'       => ['required','integer','digits:6'],

                // main RM must be valid staff
                'rm'            => ['required','integer','exists:users,id'],
                'sales_person'  => ['required','integer','exists:users,id'],

                // contact_person array
                'contact_person'             => ['required','array','min:1'],
                'contact_person.*.name'      => ['required','string','max:255'],
                'contact_person.*.rm'        => ['required','integer','exists:users,id'],
                'contact_person.*.mobile'    => ['required','string','max:20'],
                'contact_person.*.email'     => ['nullable','email','max:255'],
            ]);

            // 2️⃣ Extra validation: main RM must be staff
            $mainRm = User::find($request->rm);
            if (!$mainRm || $mainRm->role !== 'staff') {
                return response()->json([
                    'code' => 422,
                    'status' => false,
                    'message' => 'Main RM must be a valid staff user.',
                ], 422);
            }

            // 3️⃣ Extra validation: each contact person RM must be staff
            foreach ($request->contact_person as $index => $cp) {
                $rmUser = User::find($cp['rm']);
                if (!$rmUser || $rmUser->role !== 'staff') {
                    return response()->json([
                        'code' => 422,
                        'status' => false,
                        'message' => "contact_person[$index].rm must be a valid staff user.",
                    ], 422);
                }
            }

            // Extract validated data
            $clientData = $request->only([
                'name',
                'category',
                'sub_category',
                'tags',
                'city',
                'state',
                'pincode',
                'sales_person',
                'rm',
            ]);

            $contactPersons = $request->contact_person;

            // 4️⃣ Create client + contacts in DB transaction
            $result = DB::transaction(function () use ($clientData, $contactPersons) {

                // 4.1 Create client record
                $client = ClientsModel::create([
                    'name'          => $clientData['name'],
                    'category'      => (int)$clientData['category'],
                    'sub_category'  => (int)$clientData['sub_category'],
                    'tags'          => $clientData['tags'] ?? null,
                    'city'          => $clientData['city'],
                    'state'         => $clientData['state'],
                    'pincode'       => $clientData['pincode'],
                    'sales_person'  => (int)$clientData['sales_person'],
                    'rm'            => (int)$clientData['rm'],
                ]);

                // 4.2 Insert contact persons
                $createdContacts = [];
                foreach ($contactPersons as $cp) {
                    $createdContacts[] = ClientsContactPersonModel::create([
                        'client'  => $client->id,
                        'name'    => $cp['name'],
                        'rm'      => (int)$cp['rm'],     // Valid staff
                        'mobile'  => $cp['mobile'],
                        'email'   => $cp['email'] ?? null,
                    ]);
                }

                return [
                    'client'   => $client,
                    'contacts' => $createdContacts,
                ];
            });

            // 5️⃣ Success response
            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'Client & contact persons created successfully!',
                'data'    => [
                    'client'   => $result['client'],
                    'contacts' => $result['contacts'],
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
            \Log::error('Client creation failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong!',
            ], 500);
        }
    }

    // fetch
    public function fetch(Request $request, $id = null)
    {
        try {
            /* =====================================================
            *  SINGLE CLIENT FETCH
            * ===================================================== */
            if ($id !== null) {

                $client = ClientsModel::with([
                        'categoryRef:id,name',
                        'subCategoryRef:id,name',
                        'rmRef:id,name,username,email',
                        'salesRef:id,name,username,email',
                        'contactPersons.rmUser:id,name,username,email' // load contact RMs
                    ])
                    ->select('id','name','category','sub_category','tags','city','state','pincode','rm','sales_person','created_at','updated_at')
                    ->find($id);

                if (!$client) {
                    return response()->json([
                        'code'    => 404,
                        'status'  => false,
                        'message' => 'Client not found.',
                    ], 404);
                }

                // Parse tag ids → tag objects
                $tagIds = collect(explode(',', (string) $client->tags))
                    ->map(fn($v) => (int) trim($v))
                    ->filter()
                    ->unique()
                    ->values();

                $tags = $tagIds->isEmpty()
                    ? collect()
                    : TagsModel::whereIn('id', $tagIds)->select('id','name')->get();

                // Format contact persons
                $contactPersons = $client->contactPersons->map(function ($cp) {
                    return [
                        'id'     => $cp->id,
                        'name'   => $cp->name,
                        'mobile' => $cp->mobile,
                        'email'  => $cp->email,
                        'rm'     => $cp->rmUser ? [
                            'id'       => $cp->rmUser->id,
                            'name'     => $cp->rmUser->name,
                            'username' => $cp->rmUser->username,
                        ] : null,
                    ];
                });

                // Final response structure
                $data = [
                    'id'   => $client->id,
                    'name' => $client->name,

                    'category' => $client->categoryRef
                        ? ['id' => $client->categoryRef->id, 'name' => $client->categoryRef->name]
                        : null,

                    'sub_category' => $client->subCategoryRef
                        ? ['id' => $client->subCategoryRef->id, 'name' => $client->subCategoryRef->name]
                        : null,

                    'tags' => $tags->map(fn($t) => ['id' => $t->id, 'name' => $t->name])->values(),

                    'city'     => $client->city,
                    'state'    => $client->state,
                    'pincode'  => $client->pincode,

                    'rm' => $client->rmRef ? [
                        'id'       => $client->rmRef->id,
                        'name'     => $client->rmRef->name,
                        'username' => $client->rmRef->username
                    ] : null,

                    'sales_person' => $client->salesRef ? [
                        'id'       => $client->salesRef->id,
                        'name'     => $client->salesRef->name,
                        'username' => $client->salesRef->username
                    ] : null,

                    'contact_person' => $contactPersons,

                    'record_created_at' => $client->created_at,
                    'record_updated_at' => $client->updated_at,
                ];

                return response()->json([
                    'code'    => 200,
                    'status'  => true,
                    'message' => 'Client fetched successfully.',
                    'data'    => $data,
                ], 200);
            }

            /* =====================================================
            *  LIST MODE (FILTER + PAGINATION)
            * ===================================================== */

            $limit  = (int)$request->input('limit', 10);
            $offset = (int)$request->input('offset', 0);

            $search      = trim((string)$request->input('search', ''));
            $categoryRaw = $request->input('category');
            $subCatRaw   = $request->input('sub_category');
            $tagRaw      = $request->input('tags');
            $rmId        = $request->input('rm');
            $dateFrom    = $request->input('date_from');
            $dateTo      = $request->input('date_to');

            $toIntArray = fn ($str) => $str ? array_map('intval', array_filter(explode(',', $str))) : [];

            $categoryIds = $toIntArray($categoryRaw);
            $subCatIds   = $toIntArray($subCatRaw);
            $tagIds      = $toIntArray($tagRaw);

            $total = ClientsModel::count();

            $q = ClientsModel::with([
                    'categoryRef:id,name',
                    'subCategoryRef:id,name',
                    'rmRef:id,name,username,email',
                    'salesRef:id,name,username,email',
                    'contactPersons' // Do not load RM here (performance)
                ])
                ->select('id','name','category','sub_category','tags','city','state','pincode','rm','sales_person','created_at','updated_at')
                ->orderBy('id','desc');

            if ($search !== '') $q->where('name', 'like', "%{$search}%");
            if ($categoryIds)   $q->whereIn('category', $categoryIds);
            if ($subCatIds)     $q->whereIn('sub_category', $subCatIds);
            if ($tagIds) {
                $q->where(function ($q) use ($tagIds) {
                    foreach ($tagIds as $tid) {
                        $q->orWhereRaw('FIND_IN_SET(?, tags)', [$tid]);
                    }
                });
            }
            if (!empty($rmId))  $q->where('rm', (int)$rmId);
            if (!empty($dateFrom)) $q->whereDate('created_at', '>=', $dateFrom);
            if (!empty($dateTo))   $q->whereDate('created_at', '<=', $dateTo);

            $items = $q->skip($offset)->take($limit)->get();

            // Build tag map
            $allTagIds = $items->flatMap(function ($c) {
                return collect(explode(',', (string)$c->tags))
                    ->map(fn($v) => (int) trim($v))
                    ->filter();
            })->unique()->values();

            $tagMap = $allTagIds->isEmpty()
                ? collect()
                : TagsModel::whereIn('id', $allTagIds)->select('id','name')->get()->keyBy('id');

            // Final list response
            $data = $items->map(function ($c) use ($tagMap) {

                $tagIds = collect(explode(',', (string)$c->tags))
                    ->map(fn($v) => (int) trim($v))
                    ->filter()->values();

                $tagObjects = $tagIds->map(function ($id) use ($tagMap) {
                    $t = $tagMap->get($id);
                    return $t ? ['id'=>$t->id,'name'=>$t->name] : null;
                })->filter()->values();

                return [
                    'id'   => $c->id,
                    'name' => $c->name,

                    'category' => $c->categoryRef
                        ? ['id' => $c->categoryRef->id, 'name' => $c->categoryRef->name]
                        : null,

                    'sub_category' => $c->subCategoryRef
                        ? ['id' => $c->subCategoryRef->id, 'name' => $c->subCategoryRef->name]
                        : null,

                    'tags'  => $tagObjects,

                    'city'     => $c->city,
                    'state'    => $c->state,
                    'pincode'  => $c->pincode,

                    'rm' => $c->rmRef ? [
                        'id' => $c->rmRef->id,
                        'name' => $c->rmRef->name,
                    ] : null,

                    'sales_person' => $c->salesRef ? [
                        'id' => $c->salesRef->id,
                        'name' => $c->salesRef->name,
                    ] : null,

                    // Contact persons without RM details for list mode
                    'contact_person_count' => $c->contactPersons->count(),

                    'record_created_at' => $c->created_at,
                    'record_updated_at' => $c->updated_at,
                ];
            });

            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'Clients retrieved successfully.',
                'total'   => $total,
                'count'   => $data->count(),
                'data'    => $data,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Clients fetch failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while fetching clients.',
            ], 500);
        }
    }

    // edit
    public function edit(Request $request, $id)
    {
        try {
            // Step 1: Find existing record
            $client = ClientsModel::find($id);

            if (! $client) {
                return response()->json([
                    'code'    => 404,
                    'status'  => false,
                    'message' => 'Client not found!',
                ], 404);
            }

            // Step 2: Validate only data that exists — but since all are required,
            // we’ll fall back to existing values if not passed.
            $request->validate([
                'name'          => ['required','string','max:255'],
                'category'      => ['required','integer','exists:t_category,id'],
                'sub_category'  => ['required','integer','exists:t_sub_category,id'],
                'tags'          => ['required','string','max:255'], // comma-separated ids
                'city'          => ['required','string','max:255'],
                'state'         => ['required','string','max:255'],
                'pincode'       => ['required','digits_between:4,10'],
                'rm'            => ['required','integer','exists:users,id'],
                'sales_person'  => ['required','integer','exists:users,id'],
            ]);

            // Step 3: Merge inputs with old record (fallback logic)
            $payload = [
                'name'          => $request->input('name', $client->name),
                'category'      => $request->input('category', $client->category),
                'sub_category'  => $request->input('sub_category', $client->sub_category),
                'tags'          => $request->input('tags', $client->tags),
                'city'          => $request->input('city', $client->city),
                'state'         => $request->input('state', $client->state),
                'pincode'       => $request->input('pincode', $client->pincode),
                'rm'            => $request->input('rm', $client->rm),
                'sales_person'  => $request->input('sales_person', $client->rm),
            ];

            // Step 4: Update safely in a transaction
            DB::transaction(function () use ($id, $payload) {
                ClientsModel::where('id', $id)->update($payload);
            });

            // Step 5: Fetch the updated record with relationships
            $fresh = ClientsModel::with([
                    'categoryRef:id,name',
                    'subCategoryRef:id,name',
                    'rmRef:id,name,username,email',
                    'salesRef:id,name,username,email',
                ])->find($id);

            // Parse tags → object list
            $tagIds = collect(explode(',', (string) $fresh->tags))
                ->map(fn($v) => (int) trim($v))
                ->filter()
                ->unique()
                ->values();

            $tags = $tagIds->isEmpty()
                ? collect()
                : TagsModel::whereIn('id', $tagIds)->select('id','name')->get();

            // Step 6: Shape the final response
            $data = [
                'id'           => $fresh->id,
                'name'         => $fresh->name,
                'category'     => $fresh->categoryRef
                                    ? ['id'=>$fresh->categoryRef->id, 'name'=>$fresh->categoryRef->name]
                                    : null,
                'sub_category' => $fresh->subCategoryRef
                                    ? ['id'=>$fresh->subCategoryRef->id, 'name'=>$fresh->subCategoryRef->name]
                                    : null,
                'tags'         => $tags->map(fn($t)=>['id'=>$t->id,'name'=>$t->name])->values(),
                'city'         => $fresh->city,
                'state'        => $fresh->state,
                'pincode'      => $fresh->pincode,
                'rm'           => $fresh->rmRef
                                    ? ['id'=>$fresh->rmRef->id, 'name'=>$fresh->rmRef->name, 'username'=>$fresh->rmRef->username]
                                    : null,
                'sales_person' => $fresh->salesRef
                                    ? ['id'=>$fresh->salesRef->id, 'name'=>$fresh->salesRef->name, 'username'=>$fresh->salesRef->username]
                                    : null,
            ];

            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'Client updated successfully!',
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
            Log::error('Client update failed', [
                'id'    => $id,
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while updating client.',
            ], 500);
        }
    }

    // delete
    public function delete(Request $request, $id)
    {
        try {
            // Fetch with relations first (so we can return a useful snapshot)
            $client = ClientsModel::with([
                    'categoryRef:id,name',
                    'subCategoryRef:id,name',
                    'rmRef:id,name,username,email',
                    'salesRef:id,name,username,email',
                ])
                ->select('id','name','category','sub_category','tags','city','state','rm','sales_person')
                ->find($id);

            if (! $client) {
                return response()->json([
                    'code'    => 404,
                    'status'  => false,
                    'message' => 'Client not found.',
                ], 404);
            }

            // Map tags to objects
            $tagIds = collect(explode(',', (string) $client->tags))
                ->map(fn($v) => (int) trim($v))
                ->filter()
                ->unique()
                ->values();

            $tags = $tagIds->isEmpty()
                ? collect()
                : TagsModel::whereIn('id', $tagIds)->select('id','name')->get();

            // Build response snapshot BEFORE deletion
            $snapshot = [
                'id'   => $client->id,
                'name' => $client->name,
                'category' => $client->categoryRef ? [
                    'id' => $client->categoryRef->id,
                    'name' => $client->categoryRef->name,
                ] : null,
                'sub_category' => $client->subCategoryRef ? [
                    'id' => $client->subCategoryRef->id,
                    'name' => $client->subCategoryRef->name,
                ] : null,
                'tags'  => $tags->map(fn($t) => ['id'=>$t->id,'name'=>$t->name])->values(),
                'city'  => $client->city,
                'state' => $client->state,
                'rm'    => $client->rmRef ? [
                    'id' => $client->rmRef->id,
                    'name' => $client->rmRef->name,
                    'username' => $client->rmRef->username,
                ] : null,
                'sales_person' => $client->salesRef ? [
                    'id' => $client->salesRef->id,
                    'name' => $client->salesRef->name,
                    'username' => $client->salesRef->username,
                ] : null,
            ];

            // Delete
            $client->delete();

            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'Client deleted successfully!',
                'data'    => $snapshot,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Client delete failed', [
                'client_id' => $id,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return response()->json([
                'code'    => 500,
                'status'  => false,
                'message' => 'Something went wrong while deleting client.',
            ], 500);
        }
    }

    // export
    public function exportExcel(Request $request)
    {
        /* ---------- 1.  identical filter helpers as in fetch() ---------- */
        $search      = trim((string) $request->input('search', ''));
        $categoryRaw = $request->input('category');   // "1,5,9"
        $subCatRaw   = $request->input('sub_category');
        $tagRaw      = $request->input('tags');
        $rmId        = $request->input('rm');
        $dateFrom    = $request->input('date_from');  // Y-m-d
        $dateTo      = $request->input('date_to');

        $toIntArray = fn ($str) => $str
            ? array_map('intval', array_filter(explode(',', $str)))
            : [];

        $categoryIds = $toIntArray($categoryRaw);
        $subCatIds   = $toIntArray($subCatRaw);
        $tagIds      = $toIntArray($tagRaw);

        /* ---------- 2.  build identical query (no limit/offset) ---------- */
        $q = ClientsModel::with([
                'categoryRef:id,name',
                'subCategoryRef:id,name',
                'rmRef:id,name,username,email',
                'salesRef:id,name,username,email'
            ])
            ->select('id','name','category','sub_category','tags','city','state','rm','sales_person','created_at')
            ->orderBy('id','desc');

        if ($search !== '') {
            $q->where('name', 'like', "%{$search}%");
        }
        if ($categoryIds) {
            $q->whereIn('category', $categoryIds);
        }
        if ($subCatIds) {
            $q->whereIn('sub_category', $subCatIds);
        }
        if ($tagIds) {
            $q->where(function ($q) use ($tagIds) {
                foreach ($tagIds as $tid) {
                    $q->orWhereRaw('FIND_IN_SET(?, tags)', [$tid]);
                }
            });
        }
        if (!empty($rmId)) {
            $q->where('rm', (int) $rmId);
        }
        if (!empty($dateFrom)) {
            $q->whereDate('created_at', '>=', $dateFrom);
        }
        if (!empty($dateTo)) {
            $q->whereDate('created_at', '<=', $dateTo);
        }

        $rows = $q->get();   // everything that matches filters

        /* ---------- 3.  prepare Excel ---------- */
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        // headers
        $headers = [
            'SL No','Client','Category','Sub Category','Tags','City','State','RM','Sales Person'
        ];
        $sheet->fromArray($headers, null, 'A1');
        // ✅ FIX: style only A1:I1 (not J1), also center-align and fill
        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
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
                'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFEFEFEF'],
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        // data
        $rowNo = 2;
        $sl    = 1;                       // running serial
        foreach ($rows as $c) {
            $tagNames = collect(explode(',', (string) $c->tags))
                ->map(fn($v) => trim($v))
                ->filter()
                ->map(fn($id) => TagsModel::find((int)$id)?->name)
                ->filter()
                ->implode(', ');

            $sheet->fromArray([
                $sl,                      // SL NO
                $c->name,
                $c->categoryRef?->name,
                $c->subCategoryRef?->name,
                $tagNames,
                $c->city,
                $c->state,
                $c->rmRef?->name,
                $c->salesRef?->name,
            ], null, "A{$rowNo}");

            $sheet->getStyle("A{$rowNo}:I{$rowNo}")->applyFromArray([   // 9 columns now
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

        /* adjust auto-size range */
        foreach (range('A','I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        /* ---------- 4.  save to disk & return URL ---------- */
        $filename  = 'clients_export_' . now()->format('Ymd_His') . '.xlsx';
        $directory = 'clients';                      // ← storage/app/public/clients/

        if (!Storage::disk('public')->exists($directory)) {
            Storage::disk('public')->makeDirectory($directory);
        }

        $path = storage_path("app/public/{$directory}/{$filename}");
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        $publicUrl = Storage::disk('public')->url("{$directory}/{$filename}");

        return response()->json([
            'code'    => 200,
            'success' => true,
            'message' => 'Clients exported successfully.',
            'data'    => [
                'file_url' => $publicUrl,
                'count'    => $rows->count(),
            ],
        ], 200);
    }
}
