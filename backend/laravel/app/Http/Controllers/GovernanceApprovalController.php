<?php

namespace App\Http\Controllers;

use App\Models\GovernanceApproval;
use App\Services\GovernanceApprovalService;
use App\Services\GroupStaffOtpConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GovernanceApprovalController
{
    public function index(Request $request): JsonResponse
    {
        $approvals = GovernanceApproval::query()
            ->with(['governanceRequest.requester:id,name,email', 'governanceRequest.subject:id,name,email'])
            ->where('approver_user_id', $request->user()->id)
            ->where('decision', 'pending')
            ->whereHas('governanceRequest', fn ($query) => $query->where('status', 'pending'))
            ->orderBy('approval_level')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $approvals->map(fn (GovernanceApproval $approval) => [
                'id' => $approval->id,
                'approval_level' => $approval->approval_level,
                'required_authority' => $approval->required_authority,
                'otp_purpose' => 'governance.approval.decide.'.$approval->id,
                'request' => [
                    'public_id' => $approval->governanceRequest->public_id,
                    'authority' => $approval->governanceRequest->requested_authority,
                    'scope_type' => $approval->governanceRequest->scope_type,
                    'scope_key' => $approval->governanceRequest->scope_key,
                    'business_reason' => $approval->governanceRequest->business_reason,
                    'expires_at' => $approval->governanceRequest->expires_at,
                    'requester' => $approval->governanceRequest->requester,
                    'subject' => $approval->governanceRequest->subject,
                ],
            ]),
        ]);
    }

    public function decide(
        Request $request,
        GroupStaffOtpConfirmation $otp,
        GovernanceApprovalService $governance,
        int $approvalId,
    ): JsonResponse {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $approval = GovernanceApproval::query()
            ->whereKey($approvalId)
            ->where('approver_user_id', $request->user()->id)
            ->where('decision', 'pending')
            ->firstOrFail();

        $purpose = 'governance.approval.decide.'.$approval->id;
        $otpSession = $request->session()->get('nexora_group_staff_otp');

        try {
            $result = DB::transaction(function () use ($otp, $governance, $request, $approval, $data, $purpose) {
                $otp->consume($request, $purpose);

                return $governance->decide(
                    $request->user(),
                    $approval->id,
                    $data['decision'],
                    $data['comment'] ?? null,
                );
            });
        } catch (\\Throwable $e) {
            // Session state is not part of the database transaction. Restore it
            // when the decision fails so an approver can retry safely.
            if ($otpSession !== null) {
                $request->session()->put('nexora_group_staff_otp', $otpSession);
            }

            throw $e;
        }

        if ($result->status === 'expired') {
            return response()->json([
                'message' => 'This governance request has expired.',
                'request' => [
                    'public_id' => $result->public_id,
                    'status' => $result->status,
                    'completed_at' => $result->completed_at,
                ],
            ], 409);
        }

        return response()->json([
            'message' => 'Governance decision recorded.',
            'request' => [
                'public_id' => $result->public_id,
                'status' => $result->status,
                'completed_at' => $result->completed_at,
            ],
        ]);
    }
}
