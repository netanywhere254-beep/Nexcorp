<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\NexispCustomer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NexispCustomerController
{
    private const STATUSES = ['active', 'suspended', 'closed'];

    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('activeOrganization');
        $this->authorizeRead($org);
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'phone' => ['nullable', 'string', 'max:25'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $query = NexispCustomer::query()
            ->where('organization_id', $org->id)
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['phone'])) {
            $query->where('phone_hash', NexispCustomer::hashPhone($filters['phone']));
        }
        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%'.addcslashes($filters['name'], '%_\\\\').'%');
        }

        $page = $query->paginate(25)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (NexispCustomer $customer) => $this->present($customer, false))
                ->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('activeOrganization');
        Gate::authorize('manageBilling', $org);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\\+?[0-9 ()\\-]{7,20}$/'],
        ]);

        $digits = preg_replace('/\\D+/', '', $data['phone']) ?? '';
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid phone number.']);
        }

        $duplicate = ValidationException::withMessages([
            'phone' => 'A customer with this phone number already exists.',
        ]);
        $phoneHash = NexispCustomer::hashPhone($data['phone']);

        if (NexispCustomer::query()->where('organization_id', $org->id)
            ->where('phone_hash', $phoneHash)->exists()) {
            throw $duplicate;
        }

        try {
            $customer = DB::transaction(function () use ($request, $org, $data): NexispCustomer {
                $customer = NexispCustomer::create([
                    'organization_id' => $org->id,
                    'customer_number' => 'NXC-'.Str::upper((string) Str::ulid()),
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'status' => 'active',
                ]);

                $this->audit($request, 'nexisp.customer.created', $customer);

                return $customer;
            });
        } catch (UniqueConstraintViolationException) {
            // Covers concurrent requests that pass the pre-insert duplicate check.
            throw $duplicate;
        }

        return response()->json(['data' => $this->present($customer, true)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $org = $request->attributes->get('activeOrganization');
        $this->authorizeRead($org);

        return response()->json([
            'data' => $this->present(
                $this->find($org->id, $id),
                Gate::allows('manageBilling', $org),
            ),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $org = $request->attributes->get('activeOrganization');
        Gate::authorize('manageBilling', $org);
        $customer = $this->find($org->id, $id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:'.implode(',', self::STATUSES)],
        ]);

        $customer->fill($data);
        $changed = array_keys($customer->getDirty());

        if ($changed !== []) {
            DB::transaction(function () use ($request, $customer, $changed): void {
                $customer->save();
                $this->audit($request, 'nexisp.customer.updated', $customer, ['fields' => $changed]);
            });
        }

        return response()->json([
            'data' => $this->present($customer->fresh(), true),
        ]);
    }

    private function authorizeRead($org): void
    {
        abort_unless(Gate::any(['manageBilling', 'manageNetwork'], $org), 403);
    }

    private function find(int $organizationId, int $id): NexispCustomer
    {
        return NexispCustomer::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($id);
    }

    private function audit(Request $request, string $action, NexispCustomer $customer, array $metadata = []): void
    {
        AuditLog::create([
            'organization_id' => $customer->organization_id,
            'actor_user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => NexispCustomer::class,
            'subject_id' => (string) $customer->id,
            // Never store customer name or phone in audit metadata.
            'metadata' => $metadata,
        ]);
    }

    private function present(NexispCustomer $customer, bool $fullPhone): array
    {
        $phone = (string) $customer->phone;
        $masked = strlen($phone) < 8
            ? '••••'
            : substr($phone, 0, 4).str_repeat('•', max(1, strlen($phone) - 7)).substr($phone, -3);

        return [
            'id' => $customer->id,
            'customer_number' => $customer->customer_number,
            'name' => $customer->name,
            'phone' => $fullPhone ? $phone : $masked,
            'status' => $customer->status,
            'has_login' => $customer->user_id !== null,
            'created_at' => $customer->created_at,
        ];
    }
}
