<?php

namespace App\Http\Controllers;

use App\Models\NexispSubscription;
use App\Models\PaymentTransaction;
use App\Services\PaymentIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class PaymentTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageBilling', $organization);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['initiated', 'pending', 'verified', 'failed', 'cancelled'])],
            'payable_type' => ['sometimes', 'string', 'max:120'],
            'payable_id' => ['sometimes', 'integer'],
        ]);

        $query = PaymentTransaction::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('id');

        foreach (['status', 'payable_type', 'payable_id'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        return response()->json([
            'data' => $query->get()->map(fn (PaymentTransaction $tx) => $this->payload($tx)),
        ]);
    }

    public function show(Request $request, string $payment): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageBilling', $organization);

        $tx = PaymentTransaction::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($payment);

        return response()->json(['payment' => $this->payload($tx)]);
    }

    public function storeForSubscription(Request $request, string $subscription, PaymentIntentService $service): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageBilling', $organization);

        $sub = NexispSubscription::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($subscription);

        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:40', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        try {
            $tx = $service->createForSubscription(
                $sub,
                $validated['provider'],
                $validated['idempotency_key'] ?? null,
                (int) $request->user()->id,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $status = $tx->wasRecentlyCreated ? 201 : 200;
        return response()->json(['payment' => $this->payload($tx)], $status);
    }

    private function payload(PaymentTransaction $tx): array
    {
        return [
            'id' => $tx->id,
            'provider' => $tx->provider,
            'provider_reference' => $tx->provider_reference,
            'status' => $tx->status,
            'amount' => (string) $tx->amount,
            'currency' => $tx->currency,
            'idempotency_key' => $tx->idempotency_key,
            'verified_at' => $tx->verified_at?->toIso8601String(),
            'payable_type' => $tx->payable_type,
            'payable_id' => $tx->payable_id,
            'created_at' => $tx->created_at?->toIso8601String(),
            'updated_at' => $tx->updated_at?->toIso8601String(),
        ];
    }
}
