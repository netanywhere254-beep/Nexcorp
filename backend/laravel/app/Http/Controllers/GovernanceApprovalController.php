<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GovernanceApproval;
use App\Services\ApprovalOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GovernanceApprovalController extends Controller
{
    public function sendOtp(Request $request, GovernanceApproval $approval, ApprovalOtpService $otp): JsonResponse
    {
        try {
            $maskedPhone = $otp->send($approval, $request->user());

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $request->user()->id,
                'action' => 'governance.approval_otp_sent',
                'subject_type' => GovernanceApproval::class,
                'subject_id' => (string) $approval->id,
                'metadata' => ['phone' => $maskedPhone],
            ]);

            return response()->json([
                'status' => 'sent',
                'message' => 'An approval OTP has been sent to your pre-approved number.',
                'destination' => $maskedPhone,
                'expires_in_seconds' => (int) config('services.approval_sms.ttl_minutes', 5) * 60,
            ], 202);
        } catch (RuntimeException $exception) {
            $status = match ($exception->getMessage()) {
                'Approval SMS delivery is not configured.' => 503,
                'No pre-approved, verified approval phone is registered.' => 403,
                'The approval OTP could not be delivered.' => 502,
                default => 409,
            };

            return response()->json(['message' => $exception->getMessage()], $status);
        }
    }

    public function confirm(Request $request, GovernanceApproval $approval, ApprovalOtpService $otp): JsonResponse
    {
        $validated = $request->validate([
            'otp' => ['required', 'digits:6'],
            'decision' => ['required', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $otp->confirm(
                $approval,
                $request->user(),
                $validated['otp'],
                $validated['decision'],
                $validated['comment'] ?? null,
            );

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $request->user()->id,
                'action' => 'governance.approval_'.$validated['decision'],
                'subject_type' => GovernanceApproval::class,
                'subject_id' => (string) $approval->id,
                'metadata' => ['approval_level' => $approval->approval_level],
            ]);

            return response()->json([
                'status' => $validated['decision'],
                'message' => $validated['decision'] === 'approved'
                    ? 'Approval confirmed successfully.'
                    : 'Request rejection recorded successfully.',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
