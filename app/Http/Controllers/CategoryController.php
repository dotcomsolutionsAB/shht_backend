<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\CategoryModel;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    // add
    public function create(Request $request)
    {
        try {
            // 1) Validate input
            $request->validate([
                'name' => 'required|string|max:255|unique:t_category,name',
            ]);

            // 2) Insert record inside transaction
            $category = DB::transaction(function () use ($request) {
                return CategoryModel::create([
                    'name' => $request->input('name'),
                ]);
            });

            // 3) Success response
            return response()->json([
                'status'  => true,
                'message' => 'Category created successfully!',
                'data'    => [
                    'id'   => $category->id,
                    'name' => $category->name,
                ],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation error!',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Category create failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while creating category!',
            ], 500);
        }
    }

    // fetch
    public function fetch(Request $request, $id = null)
    {
        try {
            // If ID provided → fetch single category
            if ($id !== null) {
                $category = CategoryModel::select('id', 'name')->find($id);

                if (! $category) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Category not found.',
                    ], 404);
                }

                return response()->json([
                    'status'  => true,
                    'message' => 'Category fetched successfully.',
                    'data'    => $category,
                ], 200);
            }

            // Else → fetch multiple categories with limit/offset
            $limit  = (int) $request->input('limit', 10);
            $offset = (int) $request->input('offset', 0);

            $categories = CategoryModel::select('id', 'name')
                ->orderBy('id', 'desc')
                ->skip($offset)
                ->take($limit)
                ->get();

            return response()->json([
                'status'  => true,
                'message' => 'Categories fetched successfully.',
                'count'   => $categories->count(),
                'data'    => $categories,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Fetch Categories Failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while fetching categories.',
            ], 500);
        }
    }

    // update
    public function update(Request $request, $id)
    {
        try {
            // Validate: keep name required, unique but ignore current row
            $request->validate([
                'name' => ['required','string','max:255', Rule::unique('t_category','name')->ignore($id)],
            ]);

            // Column-wise update
            $updated = CategoryModel::where('id', $id)->update([
                'name' => $request->input('name'),
            ]);

            // Always return the latest record snapshot
            $category = CategoryModel::select('id','name')->find($id);

            return response()->json([
                'status'  => true,
                'message' => $updated ? 'Category updated successfully!' : 'No changes detected.',
                'data'    => $category,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation error!',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Category update failed', [
                'id'    => $id,
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while updating category!',
            ], 500);
        }
    }

    // delete
    public function delete(Request $request, $id)
    {
        try {
            // Find category first
            $category = CategoryModel::find($id);

            if (! $category) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Category not found!',
                ], 404);
            }

            // Delete the category
            $category->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Category deleted successfully!',
                'data'    => [
                    'id'   => $id,
                    'name' => $category->name,
                ],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Category delete failed', [
                'id'    => $id,
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong while deleting category!',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
