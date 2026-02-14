<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NodeCategoryResource;
use App\Http\Resources\Api\V1\NodeResource;
use App\Models\Node;
use App\Models\NodeCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NodeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Node::query()
            ->with('category')
            ->active();

        if ($request->filled('kind')) {
            $query->where('node_kind', $request->input('kind'));
        }

        if ($request->filled('category')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->input('category'));
            });
        }

        if ($request->boolean('include_premium') === false) {
            $query->free();
        }

        $nodes = $query->orderBy('name')->get();

        return NodeResource::collection($nodes);
    }

    public function categories(): AnonymousResourceCollection
    {
        $categories = NodeCategory::query()
            ->withCount('nodes')
            ->ordered()
            ->get();

        return NodeCategoryResource::collection($categories);
    }

    public function show(string $type): JsonResponse
    {
        $node = Node::query()
            ->with('category')
            ->where('type', $type)
            ->active()
            ->firstOrFail();

        return response()->json([
            'node' => new NodeResource($node),
        ]);
    }

    public function search(Request $request): AnonymousResourceCollection
    {
        $query = $request->input('q', '');

        $nodes = Node::query()
            ->with('category')
            ->active()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhere('type', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        return NodeResource::collection($nodes);
    }
}
