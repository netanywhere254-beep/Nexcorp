<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\NexoraCase;
use App\Services\CaseReferenceNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NexoraCaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cases = NexoraCase::query()
            ->where('reporter_user_id', $request->user()->id)
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'cases' => $cases->getCollection()->map(fn (NexoraCase $case) => $this->casePayload($case)),
            'pagination' => [
                'current_page' => $cases->currentPage(),
                'last_page' => $cases->lastPage(),
                'per_page' => $cases->perPage(),
                'total' => $cases->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $phoneVerified = DB::table('user_phone_verifications')
            ->where('user_id', $user->id)
            ->where('phone_e164', $user->phone)
            ->whereNotNull('verified_at')
            ->exists();

        if (! filled($user->phone) || ! $phoneVerified) {
            return response()->json([
                'message' => 'Verify your account phone number by OTP before registering a Nexora case.',
                'verification_required' => 'account_phone_otp',
            ], 403);
        }

        $data = $request->validate([
            'case_type' => ['required', Rule::in([
                'complaint',
                'support_request',
                'service_request',
                'security_incident',
                'other',
            ])],
            'title' => ['required', 'string', 'min:5', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'priority' => ['sometimes', Rule::in(['normal', 'high', 'urgent'])],
        ]);

        $case = DB::transaction(function () use ($user, $data): NexoraCase {
            $case = NexoraCase::create([
                'public_id' => (string) Str::uuid(),
                'case_reference' => app(CaseReferenceNumber::class)->next('nexora'),
                'reporter_user_id' => $user->id,
                'case_type' => $data['case_type'],
                'title' => $data['title'],
                'description' => $data['description'],
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'submitted',
                'visibility' => 'private',
            ]);

            app(\\App\\Services\\ClientChangeOutbox::class)->record(
                'nexora',
                'case',
                $case->id,
                'case.registered',
                [
                    'case_reference' => $case->case_reference,
                    'case_type' => $case->case_type,
                    'priority' => $case->priority,
                    'status' => $case->status,
                ],
                null,
                $user->id,
                'nexora-case-registered-'.$case->public_id,
            );

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $user->id,
                'action' => 'nexora.case.registered',
                'subject_type' => NexoraCase::class,
                'subject_id' => (string) $case->id,
                'metadata' => [
                    'case_reference' => $case->case_reference,
                    'case_type' => $case->case_type,
                    'priority' => $case->priority,
                ],
            ]);

            return $case;
        });

        return response()->json([
            'message' => 'Your Nexora case has been registered and is awaiting review.',
            'case' => $this->casePayload($case),
        ], 201);
    }

    private function casePayload(NexoraCase $case): array
    {
        return [
            'reference' => $case->case_reference,
            'type' => $case->case_type,
            'title' => $case->title,
            'description' => $case->description,
            'priority' => $case->priority,
            'status' => $case->status,
            'visibility' => $case->visibility,
            'registered_at' => $case->created_at?->toISOString(),
        ];
    }
}
