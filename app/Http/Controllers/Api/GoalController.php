<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SetGoalRequest;
use App\Models\Goal;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'data' => $request->user()->goals()->orderBy('category')->get(),
        ]);
    }

    public function store(SetGoalRequest $request)
    {
        $goal = Goal::updateOrCreate(
            ['user_id' => $request->user()->id, 'category' => $request->category],
            ['monthly_limit' => $request->monthly_limit],
        );

        return response()->json($goal, 201);
    }

    public function destroy(Request $request, Goal $goal)
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $goal->delete();

        return response()->json(null, 204);
    }
}