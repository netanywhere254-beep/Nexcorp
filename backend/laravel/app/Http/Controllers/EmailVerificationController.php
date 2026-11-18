<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        abort_unless(URL::hasValidSignature($request), 403, 'The verification link is invalid or has expired.');

        $user = User::query()->findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, 'The verification link is invalid.');

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email address is already verified.']);
        }

        abort_unless($user->markEmailAsVerified(), 409, 'Email verification could not be completed.');

        event(new Verified($user));

        AuditLog::create([
            'organization_id' => null,
            'actor_user_id' => $user->id,
            'action' => 'user.email_verified',
            'subject_type' => User::class,
            'subject_id' => (string) $user->id,
            'metadata' => ['source' => 'signed_email_link'],
        ]);

        return response()->json(['message' => 'Email address verified successfully.']);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email address is already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'If the account is eligible, a verification email has been sent.'], 202);
    }
}
