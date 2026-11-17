<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\GovernanceApproval;
use App\Models\GovernanceRequest;
use App\Models\GroupAuthority;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GovernanceApprovalService
{
    /**
     * Open a request from a trusted internal workflow. Never expose approvalPlan
     * as client-controlled input; it assigns the approvers and authority chain.
     */
    public function openRequest(
        User $requester,
        User $subject,
        string $authority,
        string $scopeType,
        string $scopeKey,
        string $businessReason,
        array $approvalPlan,
        ?Carbon $expiresAt = null,
    ): GovernanceRequest {
        if ($authority === 'GROUP_CEO') {
            throw ValidationException::withMessages([
                'authority' => 'CEO appointments require the separate founder/board bootstrap process.',
            ]);
        }

        if ($requester->is($subject)) {
            throw ValidationException::withMessages([
                'subject' => 'A requester cannot appoint themselves.',
            ]);
        }

        if ($requester->status !== 'active' || $subject->status !== 'active') {
            throw ValidationException::withMessages([
                'subject' => 'Requester and appointee must both be active accounts.',
            ]);
        }

        if ($businessReason === '' || count($approvalPlan) < 1) {
            throw ValidationException::withMessages([
                'approval_plan' => 'A business reason and at least one approval stage are required.',
            ]);
        }

        $levels = [];
        foreach ($approvalPlan as $step) {
            $approverId = (int) ($step['approver_user_id'] ?? 0);
            $level = (int) ($step['approval_level'] ?? 0);
            $requiredAuthority = (string) ($step['required_authority'] ?? '');

            if ($approverId < 1 || $level < 1 || $requiredAuthority === ''
                || $approverId === $requester->id || $approverId === $subject->id
                || in_array($level, $levels, true)) {
                throw ValidationException::withMessages([
                    'approval_plan' => 'The trusted approval plan is incomplete or violates separation of duties.',
                ]);
            }
            $levels[] = $level;
        }

        sort($levels);
        if ($levels !== range(1, count($approvalPlan))) {
            throw ValidationException::withMessages([
                'approval_plan' => 'Approval levels must be consecutive, starting at level one.',
            ]);
        }

        return DB::transaction(function () use (
            $requester, $subject, $authority, $scopeType, $scopeKey,
            $businessReason, $approvalPlan, $expiresAt
        ) {
            $request = GovernanceRequest::create([
                'public_id' => (string) Str::uuid(),
                'requested_by' => $requester->id,
                'subject_user_id' => $subject->id,
                'requested_authority' => $authority,
                'scope_type' => $scopeType,
                'scope_key' => $scopeKey,
                'status' => 'pending',
                'business_reason' => $businessReason,
                'expires_at' => $expiresAt,
            ]);

            foreach ($approvalPlan as $step) {
                $request->approvals()->create([
                    'approver_user_id' => $step['approver_user_id'],
                    'approval_level' => $step['approval_level'],
                    'required_authority' => $step['required_authority'],
                    'decision' => 'pending',
                ]);
            }

            $this->audit($requester, 'governance.requested', $request, [
                'authority' => $authority,
                'scope_type' => $scopeType,
                'scope_key' => $scopeKey,
                'approval_levels' => count($approvalPlan),
            ]);

            return $request->load('approvals');
        });
    }

    public function decide(
        User $actor,
        int $approvalId,
        string $decision,
        ?string $comment = null,
    ): GovernanceRequest {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Decision must be approved or rejected.',
            ]);
        }

        return DB::transaction(function () use ($actor, $approvalId, $decision, $comment) {
            $approval = GovernanceApproval::query()
                ->whereKey($approvalId)
                ->lockForUpdate()
                ->firstOrFail();

            $request = GovernanceRequest::query()
                ->whereKey($approval->governance_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== 'pending' || $approval->decision !== 'pending') {
                throw ValidationException::withMessages([
                    'approval' => 'This approval has already been decided or the request is closed.',
                ]);
            }

            if ($request->expires_at && $request->expires_at->isPast()) {
                $request->update(['status' => 'expired', 'completed_at' => now()]);
                $this->audit($actor, 'governance.expired', $request, [
                    'approval_id' => $approval->id,
                ]);

                return $request->fresh('approvals');
            }

            if ($actor->status !== 'active'
                || $actor->id !== $approval->approver_user_id
                || $actor->id === $request->requested_by
                || $actor->id === $request->subject_user_id) {
                throw new AuthorizationException('This user is not the assigned approver.');
            }

            $earlierPending = $request->approvals()
                ->where('approval_level', '<', $approval->approval_level)
                ->where('decision', '!=', 'approved')
                ->exists();

            if ($earlierPending) {
                throw ValidationException::withMessages([
                    'approval' => 'Approval stages must be completed in order.',
                ]);
            }

            $hasAuthority = GroupAuthority::query()
                ->where('user_id', $actor->id)
                ->where('authority', $approval->required_authority)
                ->where('scope_type', $request->scope_type)
                ->where('scope_key', $request->scope_key)
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists();

            if (! $hasAuthority) {
                throw new AuthorizationException('The assigned approver lacks active authority for this scope.');
            }

            $approval->update([
                'decision' => $decision,
                'comment' => $comment,
                'decided_at' => now(),
            ]);

            $this->audit($actor, 'governance.'.$decision, $request, [
                'approval_id' => $approval->id,
                'approval_level' => $approval->approval_level,
                'required_authority' => $approval->required_authority,
            ]);

            if ($decision === 'rejected') {
                $request->update(['status' => 'rejected', 'completed_at' => now()]);
                return $request->fresh('approvals');
            }

            $pending = $request->approvals()->where('decision', 'pending')->exists();
            if (! $pending) {
                $request->update(['status' => 'approved', 'completed_at' => now()]);

                GroupAuthority::create([
                    'user_id' => $request->subject_user_id,
                    'authority' => $request->requested_authority,
                    'scope_type' => $request->scope_type,
                    'scope_key' => $request->scope_key,
                    'status' => 'active',
                    'appointed_by' => $actor->id,
                    'starts_at' => now(),
                    'expires_at' => $request->expires_at,
                ]);

                $this->audit($actor, 'governance.authority_activated', $request, [
                    'authority' => $request->requested_authority,
                    'scope_type' => $request->scope_type,
                    'scope_key' => $request->scope_key,
                    'subject_user_id' => $request->subject_user_id,
                ]);
            }

            return $request->fresh('approvals');
        });
    }

    private function audit(User $actor, string $action, GovernanceRequest $request, array $metadata): void
    {
        AuditLog::create([
            'organization_id' => null,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'subject_type' => GovernanceRequest::class,
            'subject_id' => (string) $request->id,
            'metadata' => $metadata,
        ]);
    }
}
