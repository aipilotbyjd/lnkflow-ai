<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CredentialTypeResource;
use App\Models\CredentialType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CredentialTypeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $types = CredentialType::query()
            ->orderBy('name')
            ->get();

        return CredentialTypeResource::collection($types);
    }

    public function show(string $type): JsonResponse
    {
        $credentialType = CredentialType::query()
            ->where('type', $type)
            ->firstOrFail();

        return response()->json([
            'credential_type' => new CredentialTypeResource($credentialType),
        ]);
    }
}
