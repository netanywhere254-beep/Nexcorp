<?php

namespace App\Http\Controllers;

use App\Services\Nexisp\NexbookPaymentRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NexbookPaymentRefundController extends Controller
{
    public function store(Request $request, NexbookPaymentRefundService $refunds): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::forUser($request->user())->authorize('update', $organization);

        $validated = $request->validate([
            'payment_id' => ['required', 'integer', 'min:1'],
            'deposit_account_id' => ['required', 'integer', 'min:1'],
            'receivable_account_id' => ['required', 'integer', 'min:1'],
            'refund_date' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', 'in:cash,bank_transfer,mobile_money,card,cheque,other'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'reference' => ['nullable', 'string', 'max:120'],
            'allocations' => ['required', 'array', 'min:1', 'max:100'],
            'allocations.*.invoice_id' => ['required', 'integer', 'min:1'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ]);

        $refund = $refunds->refund(
            (int) $organization->id,
            (int) $validated['payment_id'],
            (int) $validated['deposit_account_id'],
            (int) $validated['receivable_account_id'],
            $validated['refund_date'],
            $validated['method'],
            $validated['reason'],
            $validated['allocations'],
            $validated['reference'] ?? null,
            (int) $request->user()->id,
        );

        return response()->json(['data' => $refund], 201);
    }
}
