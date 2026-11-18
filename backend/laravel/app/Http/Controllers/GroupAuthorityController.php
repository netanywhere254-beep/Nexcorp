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
}
