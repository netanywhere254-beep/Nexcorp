<?php

namespace App\Http\Controllers;

use App\Services\Nexisp\NexbookCreditNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NexbookCreditNoteController extends Controller
{
    public function store(Request $request, NexbookCreditNoteService $creditNotes): JsonResponse
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::forUser($request->user())->authorize('update', $organization);

        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'min:1'],
            'receivable_account_id' => ['required', 'integer', 'min:1'],
            'tax_payable_account_id' => ['nullable', 'integer', 'min:1'],
            'credit_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.invoice_line_id' => ['required', 'integer', 'min:1'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ]);

        $note = $creditNotes->issue(
            (int) $organization->id,
            (int) $validated['invoice_id'],
            (int) $validated['receivable_account_id'],
            isset($validated['tax_payable_account_id']) ? (int) $validated['tax_payable_account_id'] : null,
            $validated['credit_date'],
            $validated['reason'],
            $validated['lines'],
            (int) $request->user()->id,
        );

        return response()->json(['data' => $note], 201);
    }
}
