<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\NexispServiceArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class NexispServiceAreaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('view', $organization);

        $validated = $request->validate([
            'active' => ['sometimes', 'boolean'],
        ]);

        $query = NexispServiceArea::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name');

        if (array_key_exists('active', $validated)) {
            $query->where('is_active', $validated['active']);
        }

        return response()->json([
            'data' => $query->get()->map(fn (NexispServiceArea $area) => $this->payload($area)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageNetwork', $organization);

        if ($request->has('code')) {
            $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        }

        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('nexisp_service_areas', 'code')->where('organization_id', $organization->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $area = NexispServiceArea::create([
            'organization_id' => $organization->id,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'region' => $validated['region'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'nexisp.service_area_created',
            'subject_type' => NexispServiceArea::class,
            'subject_id' => (string) $area->id,
            'metadata' => ['code' => $area->code, 'is_active' => $area->is_active],
        ]);

        return response()->json(['service_area' => $this->payload($area)], 201);
    }

    public function show(Request $request, string $serviceArea): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('view', $organization);

        $area = NexispServiceArea::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($serviceArea);

        return response()->json(['service_area' => $this->payload($area)]);
    }

    public function update(Request $request, string $serviceArea): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::authorize('manageNetwork', $organization);

        $area = NexispServiceArea::query()
            ->where('organization_id', $organization->id)
            ->findOrFail($serviceArea);

        if ($request->has('code')) {
            $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        }

        $validated = $request->validate([
            'code' => [
                'sometimes', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('nexisp_service_areas', 'code')
                    ->where('organization_id', $organization->id)
                    ->ignore($area->id),
            ],
            'name' => ['sometimes', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $area->fill($validated)->save();

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'nexisp.service_area_updated',
            'subject_type' => NexispServiceArea::class,
            'subject_id' => (string) $area->id,
            'metadata' => ['changed_fields' => array_keys($validated)],
        ]);

        return response()->json(['service_area' => $this->payload($area)]);
    }

    private function payload(NexispServiceArea $area): array
    {
        return [
            'id' => $area->id,
            'code' => $area->code,
            'name' => $area->name,
            'region' => $area->region,
            'description' => $area->description,
            'is_active' => $area->is_active,
            'created_at' => $area->created_at?->toIso8601String(),
            'updated_at' => $area->updated_at?->toIso8601String(),
        ];
    }
}
