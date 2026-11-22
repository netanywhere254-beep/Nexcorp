<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BandwidthPlan;
use App\Models\NexispSubscription;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NexispSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('view', $organization);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['pending_payment', 'active', 'suspended', 'cancelled', 'expired'])],
            'user_id' => ['sometimes', 'integer'],
        ]);

        $query = NexispSubscription::query()
            ->where('organization_id', $organization->id)
            ->with(['user:id,name,email', 'bandwidthPlan:id,code,name'])
            ->orderByDesc('id');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        return response()->json([
            'data' => $query->get()->map(fn (NexispSubscription $sub) => $this->payload($sub)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageNetwork', $organization);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'bandwidth_plan_id' => [
                'required', 'integer',
                Rule::exists('nexisp_bandwidth_plans', 'id')->where('organization_id', $organization->id),
            ],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $idempotencyKey = isset($validated['idempotency_key']) && $validated['idempotency_key'] !== ''
            ? (string) $validated['idempotency_key']
            : null;

        if ($idempotencyKey !== null) {
            $existing = NexispSubscription::query()
                ->where('organization_id', $organization->id)
                ->where('idempotency_key', $idempotencyKey)
                ->with(['user:id,name,email', 'bandwidthPlan:id,code,name'])
                ->first();
            if ($existing) {
                return response()->json(['subscription' => $this->payload($existing)], 200);
            }
        }

        $user = User::query()->findOrFail($validated['user_id']);
        abort_unless($user->status === 'active', 422, 'Selected user must be active.');

        $plan = BandwidthPlan::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($validated['bandwidth_plan_id']);
        abort_unless($plan->is_active, 422, 'Selected bandwidth plan is not active.');

        try {
            $subscription = DB::transaction(function () use ($organization, $request, $user, $plan, $idempotencyKey) {
                if ($idempotencyKey !== null) {
                    $existing = NexispSubscription::query()
                        ->where('organization_id', $organization->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return $existing;
                    }
                }

                $subscription = NexispSubscription::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'bandwidth_plan_id' => $plan->id,
                    'subscription_number' => $this->generateSubscriptionNumber(),
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'pending_payment',
                    'price_amount' => $plan->price,
                    'currency' => $plan->currency,
                    'plan_snapshot' => [
                        'plan_id' => $plan->id,
                        'code' => $plan->code,
                        'name' => $plan->name,
                        'speed_mbps' => $plan->speed_mbps,
                        'price' => (string) $plan->price,
                        'currency' => $plan->currency,
                        'validity_days' => $plan->validity_days,
                    ],
                ]);

                AuditLog::create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $request->user()->id,
                    'action' => 'nexisp.subscription_created',
                    'subject_type' => NexispSubscription::class,
                    'subject_id' => (string) $subscription->id,
                    'metadata' => [
                        'subscription_number' => $subscription->subscription_number,
                        'plan_code' => $plan->code,
                        'user_id' => $user->id,
                        'status' => $subscription->status,
                    ],
                ]);

                return $subscription;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey !== null) {
                $existing = NexispSubscription::query()
                    ->where('organization_id', $organization->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->with(['user:id,name,email', 'bandwidthPlan:id,code,name'])
                    ->firstOrFail();
                return response()->json(['subscription' => $this->payload($existing)], 200);
            }
            throw $e;
        }

        $subscription->load(['user:id,name,email', 'bandwidthPlan:id,code,name']);
        $statusCode = $subscription->wasRecentlyCreated ? 201 : 200;

        return response()->json(['subscription' => $this->payload($subscription)], $statusCode);
    }

    public function show(Request $request, string $subscription): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('view', $organization);

        $record = NexispSubscription::query()
            ->where('organization_id', $organization->id)
            ->with(['user:id,name,email', 'bandwidthPlan:id,code,name'])
            ->findOrFail($subscription);

        return response()->json(['subscription' => $this->payload($record)]);
    }

    public function activate(Request $request, string $subscription): JsonResponse
    {
        return $this->transition($request, $subscription, 'active', ['pending_payment', 'suspended']);
    }

    public function suspend(Request $request, string $subscription): JsonResponse
    {
        return $this->transition($request, $subscription, 'suspended', ['active']);
    }

    public function cancel(Request $request, string $subscription): JsonResponse
    {
        return $this->transition($request, $subscription, 'cancelled', ['pending_payment', 'active', 'suspended']);
    }

    private function transition(Request $request, string $subscriptionId, string $to, array $from): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageNetwork', $organization);

        $subscription = NexispSubscription::query()
            ->where('organization_id', $organization->id)
            ->with(['user:id,name,email', 'bandwidthPlan:id,code,name'])
            ->findOrFail($subscriptionId);

        abort_unless(in_array($subscription->status, $from, true), 409, 'Invalid subscription status transition.');

        $fromStatus = $subscription->status;
        $updates = ['status' => $to];
        if ($to === 'active') {
            $now = now();
            $updates['activated_at'] = $subscription->activated_at ?? $now;
            $updates['starts_at'] = $subscription->starts_at ?? $now;
            if ($subscription->ends_at === null) {
                $days = (int) data_get($subscription->plan_snapshot, 'validity_days', 30);
                $updates['ends_at'] = $now->copy()->addDays(max(1, $days));
            }
        }

        $subscription->fill($updates)->save();

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'nexisp.subscription_'.$to,
            'subject_type' => NexispSubscription::class,
            'subject_id' => (string) $subscription->id,
            'metadata' => [
                'from' => $fromStatus,
                'to' => $to,
                'subscription_number' => $subscription->subscription_number,
            ],
        ]);

        return response()->json(['subscription' => $this->payload($subscription->fresh(['user:id,name,email', 'bandwidthPlan:id,code,name']))]);
    }

    private function generateSubscriptionNumber(): string
    {
        return 'SUB-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
    }

    private function payload(NexispSubscription $sub): array
    {
        return [
            'id' => $sub->id,
            'subscription_number' => $sub->subscription_number,
            'status' => $sub->status,
            'price_amount' => (string) $sub->price_amount,
            'currency' => $sub->currency,
            'idempotency_key' => $sub->idempotency_key,
            'starts_at' => $sub->starts_at?->toIso8601String(),
            'ends_at' => $sub->ends_at?->toIso8601String(),
            'activated_at' => $sub->activated_at?->toIso8601String(),
            'plan_snapshot' => $sub->plan_snapshot,
            'user' => $sub->user ? ['id' => $sub->user->id, 'name' => $sub->user->name, 'email' => $sub->user->email] : null,
            'bandwidth_plan' => $sub->bandwidthPlan ? ['id' => $sub->bandwidthPlan->id, 'code' => $sub->bandwidthPlan->code, 'name' => $sub->bandwidthPlan->name] : null,
            'created_at' => $sub->created_at?->toIso8601String(),
            'updated_at' => $sub->updated_at?->toIso8601String(),
        ];
    }
}
