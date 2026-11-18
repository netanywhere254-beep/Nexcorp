<?php

namespace App\\Http\\Controllers;

use App\\Models\\AuditLog;
use App\\Models\\Organization;
use App\\Models\\OrganizationStaffApprovedPhone;
use App\\Models\\OrganizationStaffOtpChallenge;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Str;
use Illuminate\\Validation\\ValidationException;

class OrganizationStaffOtpController
{
    private function org(Request $request): Organization
    {
        $organization = $request->attributes->get('activeOrganization');
        abort_unless($organization instanceof Organization, 409, 'Select an active organisation.');

        return $organization;
    }

    public function approveNumber(Request $request): JsonResponse
    {
        $organization = $this->org($request);
        abort_unless($request->user()->can('manageMembers', $organization), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'phone' => ['required', 'string', 'regex:/^\\+[1-9][0-9]{7,14}$/'],
        ]);

        $staff = $organization->users()
            ->wherePivot('status', 'active')
            ->where('users.id', $validated['user_id'])
            ->first();

        abort_unless($staff, 422, 'Staff user must already be an active member of this organisation.');

        $phone = $validated['phone'];
        $existingPhone = OrganizationStaffApprovedPhone::query()
            ->where('organization_id', $organization->id)
            ->where('phone_e164', $phone)
            ->first();

        abort_if(
            $existingPhone && $existingPhone->user_id !== $staff->id,
            409,
            'This number was previously assigned in this organisation and requires a controlled reassignment review.'
        );

        $approvedPhone = DB::transaction(function () use ($organization, $staff, $phone, $request) {
            $current = OrganizationStaffApprovedPhone::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($current && ($current->phone_e164 !== $phone || $current->status !== 'active')) {
                OrganizationStaffOtpChallenge::query()
                    ->where('approved_phone_id', $current->id)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);
            }

            return OrganizationStaffApprovedPhone::updateOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $staff->id],
                [
                    'phone_e164' => $phone,
                    'status' => 'active',
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                    'revoked_at' => null,
                ]
            );
        });

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'organization.staff_phone.approved',
            'subject_type' => OrganizationStaffApprovedPhone::class,
            'subject_id' => (string) $approvedPhone->id,
            'metadata' => ['staff_user_id' => $staff->id, 'phone_last_four' => substr($phone, -4)],
        ]);

        return response()->json([
            'message' => 'Staff phone approved.',
            'id' => $approvedPhone->id,
            'user_id' => $staff->id,
            'phone_last_four' => substr($phone, -4),
        ], 201);
    }

    public function revokeNumber(Request $request, int $id): JsonResponse
    {
        $organization = $this->org($request);
        abort_unless($request->user()->can('manageMembers', $organization), 403);

        $approvedPhone = OrganizationStaffApprovedPhone::query()
            ->where('organization_id', $organization->id)
            ->whereKey($id)
            ->firstOrFail();

        DB::transaction(function () use ($approvedPhone) {
            $approvedPhone->update(['status' => 'revoked', 'revoked_at' => now()]);
            OrganizationStaffOtpChallenge::query()
                ->where('approved_phone_id', $approvedPhone->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);
        });

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'organization.staff_phone.revoked',
            'subject_type' => OrganizationStaffApprovedPhone::class,
            'subject_id' => (string) $approvedPhone->id,
            'metadata' => [
                'staff_user_id' => $approvedPhone->user_id,
                'phone_last_four' => substr($approvedPhone->phone_e164, -4),
            ],
        ]);

        return response()->json(['message' => 'Staff phone revoked.']);
    }

    public function sendCode(Request $request): JsonResponse
    {
        $organization = $this->org($request);
        $validated = $request->validate([
            'purpose' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.-]{2,99}$/'],
        ]);

        $approvedPhone = OrganizationStaffApprovedPhone::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->first();

        abort_unless($approvedPhone, 403, 'No organisation-approved staff phone is registered.');

        $recent = OrganizationStaffOtpChallenge::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)
            ->latest()
            ->first();

        abort_if($recent && $recent->created_at->gt(now()->subSeconds(60)), 429, 'Wait before requesting another code.');

        $url = (string) config('services.nexora_staff_sms.url');
        $token = (string) config('services.nexora_staff_sms.token');
        abort_unless(Str::startsWith($url, 'https://'), 503, 'Nexora staff SMS is not configured.');

        $code = (string) random_int(100000, 999999);
        $http = Http::timeout(10)->acceptJson();

        if ($token !== '') {
            $http = $http->withToken($token);
        }

        $response = $http->post($url, [
            'to' => $approvedPhone->phone_e164,
            'message' => 'Nexora staff confirmation code: '.$code.'. Expires in 5 minutes.',
        ]);

        abort_unless($response->successful(), 502, 'SMS provider did not accept the message.');

        $challenge = OrganizationStaffOtpChallenge::create([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'approved_phone_id' => $approvedPhone->id,
            'purpose' => $validated['purpose'],
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
        ]);

        return response()->json([
            'message' => 'Code sent to approved staff number.',
            'challenge_id' => $challenge->id,
            'expires_in_seconds' => 300,
        ]);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $organization = $this->org($request);
        $validated = $request->validate([
            'challenge_id' => ['required', 'integer'],
            'code' => ['required', 'digits:6'],
        ]);

        $challenge = OrganizationStaffOtpChallenge::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)
            ->whereKey($validated['challenge_id'])
            ->whereNull('consumed_at')
            ->firstOrFail();

        abort_if($challenge->verified_at, 409, 'Code already verified.');
        abort_if($challenge->expires_at->isPast() || $challenge->attempts >= 5, 422, 'Code expired or attempt limit reached.');

        if (! Hash::check($validated['code'], $challenge->code_hash)) {
            $challenge->increment('attempts');
            throw ValidationException::withMessages(['code' => 'Incorrect confirmation code.']);
        }

        $approvedPhoneIsActive = OrganizationStaffApprovedPhone::query()
            ->whereKey($challenge->approved_phone_id)
            ->where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->exists();

        abort_unless($approvedPhoneIsActive, 403, 'Approved staff number was revoked.');

        $challenge->update(['verified_at' => now()]);
        $request->session()->put('nexora_staff_otp', [
            'challenge_id' => $challenge->id,
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'purpose' => $challenge->purpose,
            'verified_at' => $challenge->fresh()->verified_at->timestamp,
        ]);

        return response()->json([
            'message' => 'Staff confirmation verified.',
            'purpose' => $challenge->purpose,
            'valid_for_seconds' => 300,
        ]);
    }
}
