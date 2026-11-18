<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GovernanceApproval;
use App\Services\ApprovalOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
                'metadata' => ['delivery_method' => 'sms'],
            ]);

            return response()->json([
                'status' => 'sent',
                'message' => 'An approval OTP has been sent to your pre-approved number.',
                'destination' => $maskedPhone,
                'expires_in_seconds' => (int) config('services.approval_sms.ttl_minutes', 5) * 60,
            ], 202);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $status = match (true) {
                str_starts_with($message, 'Approval SMS delivery is not configured') => 503,
                $message === 'No pre-approved, verified approval phone is registered.' => 403,
                $message === 'The approval OTP could not be delivered.' => 502,
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

            return response()->json([
                'status' => $validated['decision'],
                'message' => $validated['decision'] === 'approved'
                    ? 'Approval confirmed successfully.'
                    : 'Request rejection recorded successfully.',
            ]);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
