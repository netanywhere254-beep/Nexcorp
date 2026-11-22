<?php

namespace App\Http\Controllers;

use App\Services\Nexisp\NexbookCustomerStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NexbookCustomerStatementController extends Controller
{
    public function show(Request $request, NexbookCustomerStatementService $statements): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::forUser($request->user())->authorize('view', $organization);

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'min:1', 'max:160'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json([
            'data' => $statements->forCustomer(
                (int) $organization->id,
                $validated['customer_name'],
                $validated['from'],
                $validated['to'],
            ),
        ]);
    }
}
