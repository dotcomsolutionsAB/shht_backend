<?php

namespace App\Http\Controllers;
use App\Models\CounterModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CounterController extends Controller
{
    //
    /**
     * Create counter
     */
    public function create(Request $request)
    {
        try {
            $request->validate([
                'number' => ['required','integer'],
                'prefix' => ['required','string','max:255'],
                'postfix'=> ['required','string','max:255'],
            ]);

            $counter = DB::transaction(function () use ($request) {
                return CounterModel::create([
                    'number'  => (int) $request->number,
                    'prefix'  => $request->prefix,
                    'postfix' => $request->postfix,
                ]);
            });

            return response()->json([
                'code'    => 201,
                'status'  => true,
                'message' => 'Counter created successfully!',
                'data'    => [
                    'id'      => $counter->id,
                    'number'  => $counter->number,
                    'prefix'  => $counter->prefix,
                    'postfix' => $counter->postfix,
                ],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 422,'status' => false, 'message' => 'Validation error!', 'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Counter create failed', ['err'=>$e->getMessage()]);
            return response()->json([
                'code' => 500,'status' => false, 'message' => 'Something went wrong while creating counter!',
            ], 500);
        }
    }

    /**
     * Fetch counters (by id or list with limit/offset)
     */
    public function fetch(Request $request, $id = null)
    {
        try {
            if ($id !== null) {
                $counter = CounterModel::select('id','number','prefix','postfix')->find($id);

                return $counter
                    ? response()->json([
                        'status' => true, 'message' => 'Counter fetched successfully.', 'data' => $counter,
                      ], 200)
                    : response()->json([
                        'status' => false, 'message' => 'Counter not found.',
                      ], 404);
            }

            $limit  = (int) $request->input('limit', 10);
            $offset = (int) $request->input('offset', 0);

            $items = CounterModel::select('id','number','prefix','postfix')
                ->orderBy('id','desc')
                ->skip($offset)->take($limit)
                ->get();

            return response()->json([
                'status'  => true,
                'message' => 'Counters fetched successfully.',
                'count'   => $items->count(),
                'data'    => $items,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Counter fetch failed', ['err'=>$e->getMessage()]);
            return response()->json([
                'status' => false, 'message' => 'Something went wrong while fetching counters.',
            ], 500);
        }
    }

    /**
     * Edit counter (all fields required, as requested)
     */
    public function edit(Request $request, $id)
    {
        try {
            $request->validate([
                'number' => ['required','integer'],
                'prefix' => ['required','string','max:255'],
                'postfix'=> ['required','string','max:255'],
            ]);

            $exists = CounterModel::where('id', $id)->exists();
            if (! $exists) {
                return response()->json([
                    'code'    => 404,
                    'status'  => false,
                    'message' => 'Counter not found.',
                ], 404);
            }

            $updated = CounterModel::where('id', $id)->update([
                'number'  => (int) $request->number,
                'prefix'  => $request->prefix,
                'postfix' => $request->postfix,
            ]);

            $fresh = CounterModel::select('id','number','prefix','postfix')->find($id);

            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => $updated ? 'Counter updated successfully!' : 'No changes detected.',
                'data'    => $fresh,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 422,'status' => false, 'message' => 'Validation error!', 'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Counter update failed', [
                'id'=>$id, 'err'=>$e->getMessage()
            ]);
            return response()->json([
                'code' => 500,'status' => false, 'message' => 'Something went wrong while updating counter.',
            ], 500);
        }
    }

    /**
     * Delete counter
     */
    public function delete(Request $request, $id)
    {
        try {
            $counter = CounterModel::select('id','number','prefix','postfix')->find($id);

            if (! $counter) {
                return response()->json([
                    'code'    => 404,
                    'status'  => false,
                    'message' => 'Counter not found.',
                ], 404);
            }

            $snapshot = $counter->toArray();
            $counter->delete();

            return response()->json([
                'code'    => 200,
                'status'  => true,
                'message' => 'Counter deleted successfully!',
                'data'    => $snapshot,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Counter delete failed', [
                'id'=>$id, 'err'=>$e->getMessage()
            ]);
            return response()->json([
                'code' => 500,'status' => false, 'message' => 'Something went wrong while deleting counter.',
            ], 500);
        }
    }

    // helper function
    public function getOrIncrementForCompany(string $company): array
    {
        $company  = strtoupper(trim($company));  // SHHT / SHAPL
        $prefix   = $company;

        // Use Jan–Dec current year format like "25"
        $now = now();
        $yy  = (int) $now->format('y');          // e.g. 25 for 2025
        $yy2 = $yy + 1;                          // e.g. 26 for next year
        $postfix = sprintf('%02d/%02d', $yy, $yy2);

        return DB::transaction(function () use ($prefix, $postfix) {
            $row = CounterModel::where('prefix', $prefix)->lockForUpdate()->first();

            if (! $row) {
                // Create first record
                $row = CounterModel::create([
                    'number'  => 1,
                    'prefix'  => $prefix,
                    'postfix' => $postfix,
                ]);
            } else {
                // Update postfix if year changed
                if ($row->postfix !== $postfix) {
                    $row->postfix = $postfix;
                }
                $row->number++;
                $row->save();
            }

            $soNo = sprintf('%s-%04d-%s', $row->prefix, $row->number, $row->postfix);
            return [
                'number'  => (int) $row->number,
                'prefix'  => $row->prefix,
                'postfix' => $row->postfix,
                'so_no'   => $soNo,
            ];
        });
    }

}
