<?php

namespace App\Services;

use App\Models\GroupStaffApprovedPhone;
use App\Models\GroupStaffOtpChallenge;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupStaffOtpConfirmation
{
    public function consume(Request $request, string $purpose): void
    {
        $session = $request->session()->get('nexora_group_staff_otp');
        $userId = (int) ($request->user()?->id ?? 0);

        if (! is_array($session)
            || ($session['purpose'] ?? null) !== $purpose
            || (int) ($session['user_id'] ?? 0) !== $userId
            || (int) ($session['verified_at'] ?? 0) < now()->subMinutes(5)->timestamp) {
            throw new AuthorizationException('A fresh Nexora Group-approved staff OTP is required.');
        }

        DB::transaction(function () use ($session, $userId, $purpose) {
            $challenge = GroupStaffOtpChallenge::whereKey($session['challenge_id'] ?? 0)
                ->where('user_id', $userId)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if (! $challenge || ! $challenge->verified_at || $challenge->consumed_at
                || $challenge->expires_at->isPast()) {
                throw new AuthorizationException('Group staff OTP is invalid, expired, or already used.');
            }

            $phoneIsActive = GroupStaffApprovedPhone::whereKey($challenge->approved_phone_id)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->exists();

            if (! $phoneIsActive) {
                throw new AuthorizationException('The approved group staff phone is no longer active.');
            }

            $challenge->update(['consumed_at' => now()]);
        });

        $request->session()->forget('nexora_group_staff_otp');
    }
}
