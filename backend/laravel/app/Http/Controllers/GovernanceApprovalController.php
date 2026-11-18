<?php

namespace App\Http\Controllers;

use App\Models\GovernanceApproval;
use App\Services\GovernanceApprovalService;
use App\Services\GroupStaffOtpConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $otp->consume($request, 'governance.approval.decide.'.$approval->id);

        $result = $governance->decide(
            $request->user(),
            $approval->id,
            $data['decision'],
            $data['comment'] ?? null,
        );

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
