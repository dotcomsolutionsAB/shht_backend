<?php

namespace App\Http\Controllers;
use App\Models\TagsModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class TagsController extends Controller
{
    //
    /**
     * Create tag
     */
    public function create(Request $request)
    {
        try {
            $request->validate([
                'name' => ['required','string','max:255','unique:t_tags,name'],
            ]);

            $tag = DB::transaction(function () use ($request) {
                return TagsModel::create([
                    'name' => $request->input('name'),
                ]);
            });

            return response()->json([
                'status'  => true,
                'message' => 'Tag created successfully!',
                'data'    => ['id' => $tag->id, 'name' => $tag->name],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false, 'message' => 'Validation error!', 'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Tag create failed', ['err' => $e->getMessage()]);
            return response()->json([
                'status' => false, 'message' => 'Something went wrong while creating tag!',
            ], 500);
        }
    }

    /**
     * Fetch tags (single by id or list with limit/offset)
     */
    public function fetch(Request $request, $id = null)
    {
        try {
            if ($id !== null) {
                $tag = TagsModel::select('id','name')->find($id);

                return $tag
                    ? response()->json([
                        'status' => true, 'message' => 'Tag fetched successfully.', 'data' => $tag,
                      ], 200)
                    : response()->json([
                        'status' => false, 'message' => 'Tag not found.',
                      ], 404);
            }

            $limit  = (int) $request->input('limit', 10);
            $offset = (int) $request->input('offset', 0);

            $tags = TagsModel::select('id','name')
                ->orderBy('id','desc')
                ->skip($offset)
                ->take($limit)
                ->get();

            return response()->json([
                'status'  => true,
                'message' => 'Tags fetched successfully.',
                'count'   => $tags->count(),
                'data'    => $tags,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Tag fetch failed', ['err' => $e->getMessage()]);
            return response()->json([
                'status' => false, 'message' => 'Something went wrong while fetching tags.',
            ], 500);
        }
    }

    /**
     * Edit tag (unique name but ignore current row)
     */
    public function edit(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => ['required','string','max:255', Rule::unique('t_tags','name')->ignore($id)],
            ]);

            $updated = TagsModel::where('id', $id)->update([
                'name' => $request->input('name'),
            ]);

            $tag = TagsModel::select('id','name')->find($id);

            return response()->json([
                'status'  => true,
                'message' => $updated ? 'Tag updated successfully!' : 'No changes detected.',
                'data'    => $tag,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false, 'message' => 'Validation error!', 'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Tag update failed', ['id' => $id, 'err' => $e->getMessage()]);
            return response()->json([
                'status' => false, 'message' => 'Something went wrong while updating tag.',
            ], 500);
        }
    }

    /**
     * Delete tag
     */
    public function delete(Request $request, $id)
    {
        try {
            $tag = TagsModel::find($id);
            if (! $tag) {
                return response()->json([
                    'status' => false, 'message' => 'Tag not found.',
                ], 404);
            }

            $tag->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Tag deleted successfully!',
                'data'    => ['id' => $id, 'name' => $tag->name],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Tag delete failed', ['id' => $id, 'err' => $e->getMessage()]);
            return response()->json([
                'status' => false, 'message' => 'Something went wrong while deleting tag.',
            ], 500);
        }
    }
}
