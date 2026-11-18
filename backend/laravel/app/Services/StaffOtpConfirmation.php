<?php
namespace App\Services;
use App\Models\OrganizationStaffApprovedPhone;
use App\Models\OrganizationStaffOtpChallenge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
class StaffOtpConfirmation
{
    public function consume(Request $request, string $purpose): void
    {
        $session = $request->session()->get('nexora_staff_otp');
        $organizationId = (int) ($request->attributes->get('activeOrganization')?->id ?? 0);
        $userId = (int) ($request->user()?->id ?? 0);

        if (! is_array($session)
            || ($session['purpose'] ?? null) !== $purpose
            || (int) ($session['organization_id'] ?? 0) !== $organizationId
            || (int) ($session['user_id'] ?? 0) !== $userId
            || (int) ($session['verified_at'] ?? 0) < now()->subMinutes(5)->timestamp) {
            throw new AuthorizationException('A fresh organisation-approved staff OTP is required.');
        }

        DB::transaction(function () use ($session, $organizationId, $userId, $purpose) {
            $challenge = OrganizationStaffOtpChallenge::whereKey($session['challenge_id'] ?? 0)
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if (! $challenge || ! $challenge->verified_at || $challenge->consumed_at
                || $challenge->expires_at->isPast()) {
                throw new AuthorizationException('Staff OTP is invalid, expired, or already used.');
            }

            $approvedPhone = OrganizationStaffApprovedPhone::whereKey($challenge->approved_phone_id)
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->exists();

            if (! $approvedPhone) {
                throw new AuthorizationException('The organisation-approved staff number is no longer active.');
            }

            $challenge->update(['consumed_at' => now()]);
        });

        $request->session()->forget('nexora_staff_otp');
    }
}