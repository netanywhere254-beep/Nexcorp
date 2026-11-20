<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GroupAuthority;
use App\Models\GroupStaffApprovedPhone;
use App\Models\GroupStaffOtpChallenge;
use App\Services\GroupStaffOtpConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupAuthorityController
{
    public function revoke(
        Request $request,
        GroupStaffOtpConfirmation $otp,
        int $id,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor && $actor->status === 'active', 403);
        abort_unless(GroupAuthority::query()
            ->where('user_id', $actor->id)
            ->where('authority', 'GROUP_CEO')
            ->where('scope_type', 'group')
            ->where('scope_key', 'nexora')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists(), 403, 'Only the active Nexora Group CEO may revoke group authorities.');

        $authority = GroupAuthority::query()
            ->whereKey($id)
            ->where('scope_type', 'group')
            ->where('scope_key', 'nexora')
            ->firstOrFail();

        abort_if($authority->user_id === $actor->id, 422, 'The acting CEO cannot revoke their own appointment.');
        $otp->consume($request, 'group.authority.revoke.'.$authority->id);

        DB::transaction(function () use ($authority, $actor) {
            // Lock CEO appointments in a stable order so concurrent revocations cannot remove the final authority.
            GroupAuthority::query()
                ->where('authority', 'GROUP_CEO')
                ->where('scope_type', 'group')
                ->where('scope_key', 'nexora')
                ->where('status', 'active')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $locked = GroupAuthority::query()->whereKey($authority->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                abort(409, 'This group authority is no longer active.');
            }

            if ($locked->authority === 'GROUP_CEO') {
                $activeCeoCount = GroupAuthority::query()
                    ->where('authority', 'GROUP_CEO')
                    ->where('scope_type', 'group')
                    ->where('scope_key', 'nexora')
                    ->where('status', 'active')
                    ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->count();

                abort_if($activeCeoCount <= 1, 409, 'The last active Group CEO cannot be revoked.');
            }

            $locked->update(['status' => 'revoked', 'expires_at' => now()]);

            $hasOtherActiveAuthority = GroupAuthority::query()
                ->where('user_id', $locked->user_id)
                ->where('scope_type', 'group')
                ->where('scope_key', 'nexora')
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists();

            if (! $hasOtherActiveAuthority) {
                $approvedPhone = GroupStaffApprovedPhone::where('user_id', $locked->user_id)
                    ->where('status', 'active')->first();
                if ($approvedPhone) {
                    $approvedPhone->update(['status' => 'revoked', 'revoked_at' => now()]);
                    GroupStaffOtpChallenge::where('approved_phone_id', $approvedPhone->id)
                        ->whereNull('consumed_at')->update(['consumed_at' => now()]);
                }
            }

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $actor->id,
                'action' => 'group.authority.revoked',
                'subject_type' => GroupAuthority::class,
                'subject_id' => (string) $locked->id,
                'metadata' => [
                    'target_user_id' => $locked->user_id,
                    'authority' => $locked->authority,
                    'scope_type' => $locked->scope_type,
                    'scope_key' => $locked->scope_key,
                ],
            ]);
        });

        return response()->json(['message' => 'Nexora Group authority revoked.']);
    }

    public function appointCountryScope(
        Request $request,
        GroupStaffOtpConfirmation $otp,
        int $id,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor && $actor->status === 'active', 403);
        abort_unless(GroupAuthority::query()
            ->where('user_id', $actor->id)
            ->where('authority', 'GROUP_CEO')
            ->where('scope_type', 'group')
            ->where('scope_key', 'nexora')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists(), 403, 'Only the active Nexora Group CEO may appoint reviewer country scopes.');

        $data = $request->validate([
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'expires_at' => ['required', 'date', 'after:now'],
        ]);
        $country = strtoupper($data['country_code']);

        $target = GroupAuthority::query()
            ->whereKey($id)
            ->whereIn('authority', ['GROUP_SECURITY_OFFICER', 'GROUP_CEO'])
            ->where('scope_type', 'group')
            ->where('scope_key', 'nexora')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->firstOrFail();

        abort_if($target->user_id === $actor->id, 422, 'A CEO cannot appoint their own country review scope.');

        $purpose = 'group.authority.country_scope.appoint.'.$target->id.'.'.$country;
        $otp->consume($request, $purpose);

        $appointment = DB::transaction(function () use ($actor, $target, $country, $data): GroupAuthority {
            $lockedTarget = GroupAuthority::query()->lockForUpdate()->findOrFail($target->id);
            abort_unless(
                $lockedTarget->status === 'active'
                    && $lockedTarget->scope_type === 'group'
                    && $lockedTarget->scope_key === 'nexora'
                    && in_array($lockedTarget->authority, ['GROUP_SECURITY_OFFICER', 'GROUP_CEO'], true)
                    && ($lockedTarget->starts_at === null || $lockedTarget->starts_at->lte(now()))
                    && ($lockedTarget->expires_at === null || $lockedTarget->expires_at->gt(now())),
                409,
                'The target group appointment is no longer active.'
            );

            $alreadyAssigned = GroupAuthority::query()
                ->where('user_id', $lockedTarget->user_id)
                ->where('authority', $lockedTarget->authority)
                ->where('scope_type', 'country')
                ->where('scope_key', $country)
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists();
            abort_if($alreadyAssigned, 409, 'An active appointment already exists for this reviewer and country.');

            $appointment = GroupAuthority::create([
                'user_id' => $lockedTarget->user_id,
                'authority' => $lockedTarget->authority,
                'scope_type' => 'country',
                'scope_key' => $country,
                'status' => 'active',
                'appointed_by' => $actor->id,
                'starts_at' => now(),
                'expires_at' => $data['expires_at'],
            ]);

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $actor->id,
                'action' => 'group.authority.country_scope.appointed',
                'subject_type' => GroupAuthority::class,
                'subject_id' => (string) $appointment->id,
                'metadata' => [
                    'target_user_id' => $lockedTarget->user_id,
                    'authority' => $lockedTarget->authority,
                    'country_code' => $country,
                    'expires_at' => $appointment->expires_at?->toISOString(),
                ],
            ]);

            return $appointment;
        });

        return response()->json([
            'message' => 'Country-scoped Trace & Find reviewer appointment created.',
            'appointment' => [
                'id' => $appointment->id,
                'user_id' => $appointment->user_id,
                'authority' => $appointment->authority,
                'country_code' => $appointment->scope_key,
                'status' => $appointment->status,
                'expires_at' => $appointment->expires_at?->toISOString(),
            ],
        ], 201);
    }


    public function revokeCountryScope(
        Request $request,
        GroupStaffOtpConfirmation $otp,
        int $id,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor && $actor->status === 'active', 403);
        abort_unless(GroupAuthority::query()
            ->where('user_id', $actor->id)
            ->where('authority', 'GROUP_CEO')
            ->where('scope_type', 'group')
            ->where('scope_key', 'nexora')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists(), 403, 'Only the active Nexora Group CEO may revoke reviewer country scopes.');

        $appointment = GroupAuthority::query()
            ->whereKey($id)
            ->whereIn('authority', ['GROUP_SECURITY_OFFICER', 'GROUP_CEO'])
            ->where('scope_type', 'country')
            ->firstOrFail();

        abort_if($appointment->user_id === $actor->id, 422, 'A CEO cannot revoke their own country review scope.');
        $otp->consume($request, 'group.authority.country_scope.revoke.'.$appointment->id);

        DB::transaction(function () use ($request, $actor, $appointment): void {
            $locked = GroupAuthority::query()->lockForUpdate()->findOrFail($appointment->id);
            abort_unless($locked->status === 'active', 409, 'This country appointment is no longer active.');
            $locked->update(['status' => 'revoked', 'expires_at' => now()]);

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $actor->id,
                'action' => 'group.authority.country_scope.revoked',
                'subject_type' => GroupAuthority::class,
                'subject_id' => (string) $locked->id,
                'metadata' => [
                    'target_user_id' => $locked->user_id,
                    'authority' => $locked->authority,
                    'country_code' => $locked->scope_key,
                ],
            ]);
        });

        return response()->json(['message' => 'Country-scoped reviewer appointment revoked.']);
    }

}
