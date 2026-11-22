<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BandwidthPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class NexispPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('view', $organization);

        $validated = $request->validate([
            'active' => ['sometimes', 'boolean'],
        ]);

        $query = BandwidthPlan::query()
            ->where('organization_id', $organization->id)
            ->orderBy('speed_mbps')
            ->orderBy('name');

        if (array_key_exists('active', $validated)) {
            $query->where('is_active', $validated['active']);
        }

        return response()->json([
            'data' => $query->get()->map(fn (BandwidthPlan $plan) => $this->payload($plan)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageNetwork', $organization);

        if ($request->has('code')) {
            $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        }
        if ($request->has('currency')) {
            $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);
        }

        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('nexisp_bandwidth_plans', 'code')->where('organization_id', $organization->id),
            ],
            'name' => ['required', 'string', 'max:160'],
            'speed_mbps' => ['required', 'integer', 'min:1', 'max:100000'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $plan = BandwidthPlan::create([
            'organization_id' => $organization->id,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'speed_mbps' => $validated['speed_mbps'],
            'price' => $validated['price'],
            'currency' => $validated['currency'],
            'validity_days' => $validated['validity_days'],
            'is_active' => $validated['is_active'] ?? true,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'nexisp.plan_created',
            'subject_type' => BandwidthPlan::class,
            'subject_id' => (string) $plan->id,
            'metadata' => [
                'code' => $plan->code,
                'price' => (string) $plan->price,
                'currency' => $plan->currency,
            ],
        ]);

        return response()->json(['plan' => $this->payload($plan)], 201);
    }

    public function show(Request $request, string $plan): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('view', $organization);

        $record = BandwidthPlan::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($plan);

        return response()->json(['plan' => $this->payload($record)]);
    }

    public function update(Request $request, string $plan): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageNetwork', $organization);

        $record = BandwidthPlan::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($plan);

        if ($request->has('code')) {
            $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        }
        if ($request->has('currency')) {
            $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);
        }

        $validated = $request->validate([
            'code' => [
                'sometimes', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('nexisp_bandwidth_plans', 'code')
                    ->where('organization_id', $organization->id)
                    ->ignore($record->id),
            ],
            'name' => ['sometimes', 'string', 'max:160'],
            'speed_mbps' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'validity_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $record->fill($validated)->save();

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'nexisp.plan_updated',
            'subject_type' => BandwidthPlan::class,
            'subject_id' => (string) $record->id,
            'metadata' => ['changed_fields' => array_keys($validated)],
        ]);

        return response()->json(['plan' => $this->payload($record)]);
    }

    private function payload(BandwidthPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'speed_mbps' => $plan->speed_mbps,
            'price' => (string) $plan->price,
            'currency' => $plan->currency,
            'validity_days' => $plan->validity_days,
            'is_active' => $plan->is_active,
            'metadata' => $plan->metadata,
            'created_at' => $plan->created_at?->toIso8601String(),
            'updated_at' => $plan->updated_at?->toIso8601String(),
        ];
    }
}
