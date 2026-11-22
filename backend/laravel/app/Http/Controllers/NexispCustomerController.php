<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\NexispCustomer;
use App\Models\NexispServiceArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class NexispCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('view', $organization);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'terminated'])],
            'service_area_id' => ['sometimes', 'integer'],
        ]);

        $query = NexispCustomer::query()
            ->where('organization_id', $organization->id)
            ->with('serviceArea')
            ->orderByDesc('id');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['service_area_id'])) {
            $query->where('service_area_id', $validated['service_area_id']);
        }

        return response()->json([
            'data' => $query->get()->map(fn (NexispCustomer $customer) => $this->payload($customer)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageNetwork', $organization);

        if ($request->has('customer_number')) {
            $request->merge(['customer_number' => strtoupper((string) $request->input('customer_number'))]);
        }
        if ($request->has('email') && $request->input('email') !== null) {
            $request->merge(['email' => strtolower((string) $request->input('email'))]);
        }

        $validated = $request->validate([
            'customer_number' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('nexisp_customers', 'customer_number')->where('organization_id', $organization->id),
            ],
            'type' => ['required', Rule::in(['residential', 'business', 'reseller', 'enterprise'])],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'terminated'])],
            'full_name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'string', 'email', 'max:254'],
            'phone' => ['required', 'string', 'max:32'],
            'physical_address' => ['nullable', 'string', 'max:255'],
            'service_area_id' => [
                'nullable', 'integer',
                Rule::exists('nexisp_service_areas', 'id')->where('organization_id', $organization->id),
            ],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'metadata' => ['sometimes', 'array'],
        ]);

        $customer = NexispCustomer::create([
            'organization_id' => $organization->id,
            'service_area_id' => $validated['service_area_id'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
            'customer_number' => $validated['customer_number'],
            'type' => $validated['type'],
            'status' => $validated['status'] ?? 'active',
            'full_name' => $validated['full_name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'],
            'physical_address' => $validated['physical_address'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'nexisp.customer_created',
            'subject_type' => NexispCustomer::class,
            'subject_id' => (string) $customer->id,
            'metadata' => ['customer_number' => $customer->customer_number, 'type' => $customer->type],
        ]);

        return response()->json(['customer' => $this->payload($customer->load('serviceArea'))], 201);
    }

    public function show(Request $request, string $customer): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('view', $organization);

        $record = NexispCustomer::query()
            ->where('organization_id', $organization->id)
            ->with('serviceArea')
            ->findOrFail($customer);

        return response()->json(['customer' => $this->payload($record)]);
    }

    public function update(Request $request, string $customer): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageNetwork', $organization);

        $record = NexispCustomer::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($customer);

        if ($request->has('customer_number')) {
            $request->merge(['customer_number' => strtoupper((string) $request->input('customer_number'))]);
        }
        if ($request->has('email') && $request->input('email') !== null) {
            $request->merge(['email' => strtolower((string) $request->input('email'))]);
        }

        $validated = $request->validate([
            'customer_number' => [
                'sometimes', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('nexisp_customers', 'customer_number')
                    ->where('organization_id', $organization->id)
                    ->ignore($record->id),
            ],
            'type' => ['sometimes', Rule::in(['residential', 'business', 'reseller', 'enterprise'])],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'terminated'])],
            'full_name' => ['sometimes', 'string', 'max:160'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:254'],
            'phone' => ['sometimes', 'string', 'max:32'],
            'physical_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service_area_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('nexisp_service_areas', 'id')->where('organization_id', $organization->id),
            ],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $record->fill($validated)->save();

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'nexisp.customer_updated',
            'subject_type' => NexispCustomer::class,
            'subject_id' => (string) $record->id,
            'metadata' => ['changed_fields' => array_keys($validated)],
        ]);

        return response()->json(['customer' => $this->payload($record->load('serviceArea'))]);
    }

    private function payload(NexispCustomer $customer): array
    {
        return [
            'id' => $customer->id,
            'customer_number' => $customer->customer_number,
            'type' => $customer->type,
            'status' => $customer->status,
            'full_name' => $customer->full_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'physical_address' => $customer->physical_address,
            'service_area' => $customer->serviceArea ? [
                'id' => $customer->serviceArea->id,
                'code' => $customer->serviceArea->code,
                'name' => $customer->serviceArea->name,
            ] : null,
            'user_id' => $customer->user_id,
            'metadata' => $customer->metadata,
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }
}
