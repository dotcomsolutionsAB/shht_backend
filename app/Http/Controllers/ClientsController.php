<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Models\ClientsModel;
use App\Models\TagsModel;
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
            // 🔹 1. Validation rules
            $request->validate([
                'name'          => ['required', 'string', 'max:255'],
                'category'      => ['required', 'integer', 'exists:t_category,id'],
                'sub_category'  => ['required', 'integer', 'exists:t_sub_category,id'],
                'tags'          => ['required', 'string', 'max:255'],
                'city'          => ['required', 'string', 'max:255'],
                'state'         => ['required', 'string', 'max:255'],
                'rm'            => ['required', 'integer', 'exists:users,id'], // RM must exist in users
                'sales_person'  => ['required', 'integer', 'exists:users,id'], // Sales Person must exist in users
            ]);

            // 🔹 2. Insert record in a transaction
            $client = DB::transaction(function () use ($request) {
                return ClientsModel::create([
                    'name'          => $request->input('name'),
                    'category'      => (int) $request->input('category'),
                    'sub_category'  => (int) $request->input('sub_category'),
                    'tags'          => $request->input('tags'),
                    'city'          => $request->input('city'),
                    'state'         => $request->input('state'),
                    'rm'            => (int) $request->input('rm'),
                    'sales_person'  => (int) $request->input('sales_person'),
                ]);
            });

            // 🔹 3. Success response
            return response()->json([
                'status'  => true,
                'message' => 'Client created successfully!',
                'data'    => [
                    'id'            => $client->id,
                    'name'          => $client->name,
                    'category'      => $client->category,
                    'sub_category'  => $client->sub_category,
                    'tags'          => $client->tags,
                    'city'          => $client->city,
                    'state'         => $client->state,
                    'rm'            => $client->rm,
                    'sales_person'  => $client->sales_person,
                ],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed!',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Client create failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while creating client!',
            ], 500);
        }
    }

    // fetch
    public function fetch(Request $request, $id = null)
    {
        try {
            // ---------- Single record ----------
            if ($id !== null) {
                $client = ClientsModel::with([
                        'categoryRef:id,name',
                        'subCategoryRef:id,name',
                        'rmRef:id,name,username,email',
                        'salesRef:id,name,username,email'
                    ])
                    ->select('id','name','category','sub_category','tags','city','state','rm', 'sales_person')
                    ->find($id);

                if (! $client) {
                    return response()->json([
                        'code'    => 404,
                        'status'  => false,
                        'message' => 'Client not found.',
                    ], 404);
                }

                // Parse tag ids and fetch tag objects
                $tagIds = collect(explode(',', (string) $client->tags))
                    ->map(fn($v) => (int) trim($v))
                    ->filter()
                    ->unique()
                    ->values();

                $tags = $tagIds->isEmpty()
                    ? collect()
                    : TagsModel::whereIn('id', $tagIds)->select('id','name')->get();

                // Shape response
                $data = [
                    'id'   => $client->id,
                    'name' => $client->name,
                    'category' => $client->categoryRef
                        ? ['id' => $client->categoryRef->id, 'name' => $client->categoryRef->name]
                        : null,
                    'sub_category' => $client->subCategoryRef
                        ? ['id' => $client->subCategoryRef->id, 'name' => $client->subCategoryRef->name]
                        : null,
                    'tags' => $tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
                    'city'  => $client->city,
                    'state' => $client->state,
                    'rm'    => $client->rmRef
                        ? ['id' => $client->rmRef->id, 'name' => $client->rmRef->name, 'username' => $client->rmRef->username]
                        : null,
                    'sales_person'    => $client->salesRef
                        ? ['id' => $client->salesRef->id, 'name' => $client->salesRef->name, 'username' => $client->salesRef->username]
                        : null,
                ];

                return response()->json([
                    'code'    => 200,
                    'status'  => true,
                    'message' => 'Client fetched successfully.',
                    'data'    => $data,
                ], 200);
            }

            // ---------- List with filters + pagination ----------
            $limit       = (int) $request->input('limit', 10);
            $offset      = (int) $request->input('offset', 0);
            $search      = trim((string) $request->input('search', ''));        // client name
            $categoryRaw = $request->input('category');     // "1,5,9"
            $subCatRaw   = $request->input('sub_category'); // "11,12"
            $tagRaw      = $request->input('tags');         // "101,102,103"
            $rmId        = $request->input('rm');                                // rm user id
            $dateFrom    = $request->input('date_from');                         // YYYY-MM-DD
            $dateTo      = $request->input('date_to');                           // YYYY-MM-DD


            // helper: "1,5,9" → [1,5,9]  (int[])
            $toIntArray = fn ($str) => $str
                ? array_map('intval', array_filter(explode(',', $str)))
                : [];

            $categoryIds = $toIntArray($categoryRaw);
            $subCatIds   = $toIntArray($subCatRaw);
            $tagIds      = $toIntArray($tagRaw);

            // total BEFORE filters
            $total = ClientsModel::count();

            $q = ClientsModel::with([
                    'categoryRef:id,name',
                    'subCategoryRef:id,name',
                    'rmRef:id,name,username,email',
                    'salesRef:id,name,username,email'
                ])
                ->select('id','name','category','sub_category','tags','city','state','rm','sales_person','created_at','updated_at')
                ->orderBy('id','desc');

            // ----- Filters -----
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
                // tags stored as comma-separated string → use FIND_IN_SET
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

            // Pagination
            $items = $q->skip($offset)->take($limit)->get();

            // Build a tag map for all items in this page (single query)
            $allTagIds = $items->flatMap(function ($c) {
                    return collect(explode(',', (string) $c->tags))
                        ->map(fn($v) => (int) trim($v))
                        ->filter();
                })
                ->unique()
                ->values();

            $tagMap = $allTagIds->isEmpty()
                ? collect()
                : TagsModel::whereIn('id', $allTagIds)
                    ->select('id','name')
                    ->get()
                    ->keyBy('id');

            $data = $items->map(function ($c) use ($tagMap) {
                $tagIds = collect(explode(',', (string) $c->tags))
                    ->map(fn($v) => (int) trim($v))
                    ->filter()
                    ->values();

                $tagObjects = $tagIds->map(function ($id) use ($tagMap) {
                    $t = $tagMap->get($id);
                    return $t ? ['id' => $t->id, 'name' => $t->name] : null;
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
                    'city'  => $c->city,
                    'state' => $c->state,
                    'rm'    => $c->rmRef
                        ? ['id' => $c->rmRef->id, 'name' => $c->rmRef->name, 'username' => $c->rmRef->username]
                        : null,
                    'sales_person'    => $c->salesRef
                        ? ['id' => $c->salesRef->id, 'name' => $c->salesRef->name, 'username' => $c->salesRef->username]
                        : null,
                ];
            });

            return response()->json([
                'code'    => 200,
                'status'  => 'success',
                'message' => 'Clients retrieved successfully.',
                'total'   => $total,           // before filters
                'count'   => $data->count(),   // after filters + pagination
                'data'    => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Clients fetch failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
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
                'rm'           => $fresh->rmRef
                                    ? ['id'=>$fresh->rmRef->id, 'name'=>$fresh->rmRef->name, 'username'=>$fresh->rmRef->username]
                                    : null,
                'sales_person' => $fresh->salesRef
                                    ? ['id'=>$fresh->salesRef->id, 'name'=>$fresh->salesRef->name, 'username'=>$fresh->salesRef->username]
                                    : null,
            ];

            return response()->json([
                'status'  => true,
                'message' => 'Client updated successfully!',
                'data'    => $data,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
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
