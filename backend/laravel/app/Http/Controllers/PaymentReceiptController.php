<?php

namespace App\Http\Controllers;

use App\Models\PaymentReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PaymentReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageBilling', $organization);

        $receipts = PaymentReceipt::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn (PaymentReceipt $receipt) => $this->payload($receipt));

        return response()->json(['data' => $receipts]);
    }

    public function show(Request $request, string $receipt): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageBilling', $organization);

        $record = PaymentReceipt::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($receipt);

        return response()->json(['receipt' => $this->payload($record)]);
    }

    private function payload(PaymentReceipt $receipt): array
    {
        return [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'payment_transaction_id' => $receipt->payment_transaction_id,
            'user_id' => $receipt->user_id,
            'amount' => (string) $receipt->amount,
            'currency' => $receipt->currency,
            'provider' => $receipt->provider,
            'provider_reference' => $receipt->provider_reference,
            'payable_type' => $receipt->payable_type,
            'payable_id' => $receipt->payable_id,
            'issued_at' => $receipt->issued_at?->toIso8601String(),
            'snapshot' => $receipt->snapshot,
        ];
    }
}
