<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GroupAuthority;
use App\Models\GroupStaffApprovedPhone;
use App\Models\GroupStaffOtpChallenge;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GroupStaffOtpController
{
    private function hasGroupAuthority(int $userId, array $authorities): bool
    {
        if (! User::whereKey($userId)->where('status', 'active')->exists()) {
            return false;
        }

        return GroupAuthority::query()
            ->where('user_id', $userId)
            ->whereIn('authority', $authorities)
            ->where('scope_type', 'group')
            ->where('scope_key', 'nexora')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    public function approveNumber(Request $request): JsonResponse
    {
        abort_unless($this->hasGroupAuthority($request->user()->id, ['GROUP_CEO', 'GROUP_SECURITY_OFFICER']), 403);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ]);

        $staff = User::whereKey($data['user_id'])->where('status', 'active')->firstOrFail();
        abort_unless($this->hasGroupAuthority($staff->id, [
            'GROUP_CEO', 'GROUP_DIRECTOR', 'DEPARTMENT_HEAD',
            'GROUP_SECURITY_OFFICER', 'GROUP_FINANCE_OFFICER', 'GROUP_AUDITOR',
        ]), 422, 'The target account must hold an active Nexora Group appointment.');
        $phone = $data['phone'];

        $approved = DB::transaction(function () use ($request, $staff, $phone) {
            $existing = GroupStaffApprovedPhone::where('user_id', $staff->id)->lockForUpdate()->first();
            $numberOwner = GroupStaffApprovedPhone::where('phone_e164', $phone)->lockForUpdate()->first();

            abort_if($numberOwner && $numberOwner->user_id !== $staff->id, 409,
                'This phone number is already assigned to another group staff account.');

            if ($existing && ($existing->phone_e164 !== $phone || $existing->status !== 'active')) {
                GroupStaffOtpChallenge::where('approved_phone_id', $existing->id)
                    ->whereNull('consumed_at')->update(['consumed_at' => now()]);
            }

            return GroupStaffApprovedPhone::updateOrCreate(
                ['user_id' => $staff->id],
                [
                    'phone_e164' => $phone,
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                    'status' => 'active',
                    'revoked_at' => null,
                ],
            );
        });

        AuditLog::create([
            'organization_id' => null,
            'actor_user_id' => $request->user()->id,
            'action' => 'group.staff_phone.approved',
            'subject_type' => GroupStaffApprovedPhone::class,
            'subject_id' => (string) $approved->id,
            'metadata' => ['staff_user_id' => $staff->id, 'phone_last_four' => substr($phone, -4)],
        ]);

        return response()->json([
            'message' => 'Nexora Group staff phone approved.',
            'user_id' => $staff->id,
            'phone_last_four' => substr($phone, -4),
        ], 201);
    }

    public function revokeNumber(Request $request, int $id): JsonResponse
    {
        abort_unless($this->hasGroupAuthority($request->user()->id, ['GROUP_CEO', 'GROUP_SECURITY_OFFICER']), 403);

        $phone = GroupStaffApprovedPhone::whereKey($id)->firstOrFail();
        DB::transaction(function () use ($phone) {
            $phone->update(['status' => 'revoked', 'revoked_at' => now()]);
            GroupStaffOtpChallenge::where('approved_phone_id', $phone->id)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);
        });

        AuditLog::create([
            'organization_id' => null,
            'actor_user_id' => $request->user()->id,
            'action' => 'group.staff_phone.revoked',
            'subject_type' => GroupStaffApprovedPhone::class,
            'subject_id' => (string) $phone->id,
            'metadata' => ['staff_user_id' => $phone->user_id, 'phone_last_four' => substr($phone->phone_e164, -4)],
        ]);

        return response()->json(['message' => 'Nexora Group staff phone revoked.']);
    }

    public function sendCode(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        abort_unless($this->hasGroupAuthority($userId, [
            'GROUP_CEO', 'GROUP_DIRECTOR', 'DEPARTMENT_HEAD',
            'GROUP_SECURITY_OFFICER', 'GROUP_FINANCE_OFFICER', 'GROUP_AUDITOR',
        ]), 403, 'An active Nexora Group authority is required.');

        $data = $request->validate([
            'purpose' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.-]{2,99}$/'],
        ]);

        $phone = GroupStaffApprovedPhone::where('user_id', $userId)->where('status', 'active')->first();
        abort_unless($phone, 403, 'No group-approved staff phone is registered.');

        $recent = GroupStaffOtpChallenge::where('user_id', $userId)->latest()->first();
        abort_if($recent && $recent->created_at->gt(now()->subSeconds(60)), 429,
            'Wait before requesting another group staff code.');

        $url = (string) config('services.nexora_group_sms.url');
        $token = (string) config('services.nexora_group_sms.token');
        abort_unless(Str::startsWith($url, 'https://'), 503, 'Nexora Group staff SMS is not configured.');

        $code = (string) random_int(100000, 999999);
        $http = Http::timeout(10)->acceptJson();
        if ($token !== '') {
            $http = $http->withToken($token);
        }

        $response = $http->post($url, [
            'to' => $phone->phone_e164,
            'message' => 'Nexora Group approval code: '.$code.'. Expires in 5 minutes.',
        ]);
        abort_unless($response->successful(), 502, 'SMS provider did not accept the message.');

        $challenge = GroupStaffOtpChallenge::create([
            'user_id' => $userId,
            'approved_phone_id' => $phone->id,
            'purpose' => $data['purpose'],
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
        ]);

        return response()->json([
            'message' => 'Code sent to the pre-approved group staff number.',
            'challenge_id' => $challenge->id,
            'expires_in_seconds' => 300,
        ]);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'integer'],
            'code' => ['required', 'digits:6'],
        ]);

        $challenge = GroupStaffOtpChallenge::whereKey($data['challenge_id'])
            ->where('user_id', $request->user()->id)
            ->whereNull('consumed_at')
            ->firstOrFail();

        abort_if($challenge->verified_at, 409, 'This code has already been verified.');
        abort_if($challenge->expires_at->isPast() || $challenge->attempts >= 5, 422,
            'The code expired or the attempt limit was reached.');

        $valid = DB::transaction(function () use ($challenge, $data) {
            $locked = GroupStaffOtpChallenge::whereKey($challenge->id)->lockForUpdate()->firstOrFail();
            if ($locked->verified_at || $locked->consumed_at || $locked->expires_at->isPast()
                || $locked->attempts >= 5) {
                return false;
            }

            if (! Hash::check($data['code'], $locked->code_hash)) {
                $locked->increment('attempts');
                return false;
            }

            $activePhone = GroupStaffApprovedPhone::whereKey($locked->approved_phone_id)
                ->where('user_id', $locked->user_id)->where('status', 'active')->exists();
            if (! $activePhone) {
                return false;
            }

            $locked->update(['verified_at' => now()]);
            return true;
        });

        if (! $valid) {
            throw ValidationException::withMessages(['code' => 'The code is incorrect, expired, revoked, or already used.']);
        }

        $request->session()->put('nexora_group_staff_otp', [
            'challenge_id' => $challenge->id,
            'user_id' => $request->user()->id,
            'purpose' => $challenge->purpose,
            'verified_at' => now()->timestamp,
        ]);

        return response()->json([
            'message' => 'Group staff confirmation verified.',
            'purpose' => $challenge->purpose,
            'valid_for_seconds' => 300,
        ]);
    }
}
