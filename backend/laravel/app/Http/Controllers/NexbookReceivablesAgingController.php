<?php

namespace App\Http\Controllers;

use App\Services\Nexisp\NexbookReceivablesAgingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NexbookReceivablesAgingController extends Controller
{
    public function show(Request $request, NexbookReceivablesAgingService $aging): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::forUser($request->user())->authorize('view', $organization);

        $validated = $request->validate([
            'as_of' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $aging->report((int) $organization->id, $validated['as_of']),
        ]);
    }
}
