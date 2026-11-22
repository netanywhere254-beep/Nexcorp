<?php

namespace App\Http\Controllers;

use App\Models\BandwidthPlan;
use App\Models\NexispCustomer;
use App\Models\NexispInvoice;
use App\Models\Organization;
use App\Services\NexispBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NexispBillingController
{
    public function __construct(private NexispBillingService $billing)
    {
    }

    public function subscribe(Request $request, int $customerId): JsonResponse
    {
        $org = $this->organization($request);
        Gate::authorize('manageBilling', $org);
        $data = $request->validate(['plan_id' => ['required', 'integer']]);

        $customer = NexispCustomer::query()
            ->where('organization_id', $org->id)->findOrFail($customerId);
        $plan = BandwidthPlan::query()
            ->where('organization_id', $org->id)->findOrFail($data['plan_id']);

        $invoice = $this->billing->subscribe($org, $customer, $plan, $request->user());
        return response()->json(['data' => $this->present($invoice)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $org = $this->organization($request);
        $this->authorizeRead($org);
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,partially_paid,paid,void,overdue'],
            'customer_id' => ['nullable', 'integer'],
        ]);

        $query = NexispInvoice::query()->with(['subscription', 'customer:id,name,customer_number'])
            ->where('organization_id', $org->id)->orderByDesc('id');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        $page = $query->paginate(25)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (NexispInvoice $invoice) => $this->present($invoice))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $org = $this->organization($request);
        $this->authorizeRead($org);
        return response()->json(['data' => $this->present($this->find($org->id, $id))]);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $org = $this->organization($request);
        Gate::authorize('manageBilling', $org);
        $invoice = $this->find($org->id, $id);
        $voided = $this->billing->voidInvoice($org, $invoice->id, $request->user());
        return response()->json(['data' => $this->present($voided)]);
    }

    private function organization(Request $request): Organization
    {
        $org = $request->attributes->get('activeOrganization');
        abort_unless($org instanceof Organization, 409, 'Select an active organization first.');
        return $org;
    }

    private function authorizeRead(Organization $org): void
    {
        abort_unless(Gate::any(['manageBilling', 'manageNetwork'], $org), 403);
    }

    private function find(int $organizationId, int $id): NexispInvoice
    {
        return NexispInvoice::query()->where('organization_id', $organizationId)
            ->with(['subscription', 'customer:id,name,customer_number'])->findOrFail($id);
    }

    private function present(NexispInvoice $invoice): array
    {
        $invoice->loadMissing(['subscription', 'customer']);
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'customer_id' => $invoice->customer_id,
            'customer' => $invoice->customer ? [
                'name' => $invoice->customer->name,
                'customer_number' => $invoice->customer->customer_number,
            ] : null,
            'status' => $invoice->status,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'issued_at' => $invoice->issued_at,
            'due_at' => $invoice->due_at,
            'paid_at' => $invoice->paid_at,
            'subscription' => $invoice->subscription ? [
                'subscription_number' => $invoice->subscription->subscription_number,
                'status' => $invoice->subscription->status,
                'starts_at' => $invoice->subscription->starts_at,
                'ends_at' => $invoice->subscription->ends_at,
            ] : null,
        ];
    }
}
