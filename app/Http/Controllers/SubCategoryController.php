<?php

namespace App\Http\Controllers;
use App\Models\SubCategoryModel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class SubCategoryController extends Controller
{
    //
    /**
     * Create sub-category
     */
    public function create(Request $request)
    {
        try {
            $request->validate([
                'category' => ['required','integer','exists:t_category,id'],
                'name'     => [
                    'required','string','max:255',
                    // unique name within the same category (optional but useful)
                    Rule::unique('t_sub_category','name')->where(fn($q) => $q->where('category', $request->category)),
                ],
            ]);

            $sub = DB::transaction(function () use ($request) {
                return SubCategoryModel::create([
                    'category' => (int) $request->category,
                    'name'     => $request->name,
                ]);
            });

            return response()->json([
                'status'  => true,
                'message' => 'Sub-category created successfully!',
                'data'    => ['id'=>$sub->id, 'category'=>$sub->category, 'name'=>$sub->name],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status'=>false,'message'=>'Validation error','errors'=>$e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('SubCategory create failed', ['err'=>$e->getMessage()]);
            return response()->json(['status'=>false,'message'=>'Something went wrong while creating sub-category'], 500);
        }
    }

    /**
     * Fetch (list or single by id). Supports limit/offset and optional category filter.
     */
    public function fetch(Request $request, $id = null)
    {
        try {
            if ($id !== null) {
                $sub = SubCategoryModel::with(['categoryRef:id,name'])
                    ->select('id','category','name') // use 'category_id' if that's your column
                    ->find($id);

                if (! $sub) {
                    return response()->json(['status'=>false,'message'=>'Sub-category not found'], 404);
                }

                // Shape single item: category as object
                $data = [
                    'id'       => $sub->id,
                    'name'     => $sub->name,
                    'category' => $sub->categoryRef
                        ? ['id' => $sub->categoryRef->id, 'name' => $sub->categoryRef->name]
                        : null,
                ];

                return response()->json([
                    'status'  => true,
                    'message' => 'Sub-category fetched successfully',
                    'data'    => $data,
                ], 200);
            }

            // List with limit/offset and optional category filter
            $limit    = (int) $request->input('limit', 10);
            $offset   = (int) $request->input('offset', 0);
            $category = $request->input('category'); // optional filter (id)

            $query = SubCategoryModel::with(['categoryRef:id,name'])
                ->select('id','category','name') // use 'category_id' if applicable
                ->orderBy('id','desc');

            if ($category !== null) {
                $query->where('category', (int) $category); // or 'category_id'
            }

            $items = $query->skip($offset)->take($limit)->get();

            // Map to desired shape
            $data = $items->map(function ($sub) {
                return [
                    'id'       => $sub->id,
                    'name'     => $sub->name,
                    'category' => $sub->categoryRef
                        ? ['id' => $sub->categoryRef->id, 'name' => $sub->categoryRef->name]
                        : null,
                ];
            });

            return response()->json([
                'status'  => true,
                'message' => 'Sub-categories fetched successfully',
                'count'   => $data->count(),
                'data'    => $data,
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('SubCategory fetch failed', ['err'=>$e->getMessage()]);
            return response()->json([
                'status'=>false,
                'message'=>'Something went wrong while fetching sub-categories'
            ], 500);
        }
    }


    /**
     * Edit sub-category (unique name within same category, ignoring current row).
     */
    public function edit(Request $request, $id)
    {
        try {
            $request->validate([
                'category' => ['required','integer','exists:t_category,id'],
                'name'     => [
                    'required','string','max:255',
                    Rule::unique('t_sub_category','name')
                        ->ignore($id)
                        ->where(fn($q) => $q->where('category', $request->category)),
                ],
            ]);

            $updated = SubCategoryModel::where('id', $id)->update([
                'category' => (int) $request->category,
                'name'     => $request->name,
            ]);

            $sub = SubCategoryModel::select('id','category','name')->find($id);

            return response()->json([
                'status'  => true,
                'message' => $updated ? 'Sub-category updated successfully!' : 'No changes detected.',
                'data'    => $sub,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status'=>false,'message'=>'Validation error','errors'=>$e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('SubCategory update failed', ['id'=>$id, 'err'=>$e->getMessage()]);
            return response()->json(['status'=>false,'message'=>'Something went wrong while updating sub-category'], 500);
        }
    }

    /**
     * Delete sub-category
     */
    public function delete(Request $request, $id)
    {
        try {
            $sub = SubCategoryModel::find($id);
            if (! $sub) {
                return response()->json(['status'=>false,'message'=>'Sub-category not found'], 404);
            }

            $sub->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Sub-category deleted successfully!',
                'data'    => ['id'=>$id, 'name'=>$sub->name, 'category'=>$sub->category],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('SubCategory delete failed', ['id'=>$id,'err'=>$e->getMessage()]);
            return response()->json(['status'=>false,'message'=>'Something went wrong while deleting sub-category'], 500);
        }
    }
}
