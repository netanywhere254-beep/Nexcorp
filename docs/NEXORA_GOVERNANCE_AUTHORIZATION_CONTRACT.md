# Nexora Group Governance & Authorization Contract

Status: implementation baseline for the Laravel primary; parity contract for a future active NestJS standby.

## Non-negotiable boundaries

- Public registration creates a basic user only. It cannot create a group authority, organization membership, department appointment, or product administrator.
- Group authority and tenant/product authority are separate concepts. A group CEO is not automatically a NexISP operator or Loopza operator.
- All authority is scoped, time-bounded where appropriate, auditable, and revocable.
- No person may approve their own appointment or request.
- An approval decision is valid only for the approval level and authority assigned to that approval record.
- A role name supplied by a browser, API client, import file, or user profile is never proof of authority.
- Privileged actions require MFA in production. Session authentication alone is not sufficient for high-impact approvals.

## Group authority vocabulary

Initial controlled authority identifiers:

- GROUP_CEO
- GROUP_DIRECTOR
- DEPARTMENT_HEAD
- GROUP_SECURITY_OFFICER
- GROUP_FINANCE_OFFICER
- GROUP_AUDITOR

Product and organization permissions remain separately scoped, for example nexisp.operator, nexisp.network.manage, loopza.campaign.manage, and organization.billing.manage. These are permission identifiers, not public registration roles.

## Approval model

A governance request identifies the requester, subject user, requested authority, scope, business reason, status, and expiry. Each required approval is a separate immutable decision record with an approval level, required authority, approver, decision, timestamp, and optional comment.

Suggested minimum approval paths:

- Department or product administrator: relevant department head or product director, then a designated group executive.
- Department head: relevant group director, then CEO or formally delegated executive authority.
- Group director: CEO approval.
- CEO appointment or replacement: reserved founder/board-level process outside ordinary application self-service. The application must not let an existing CEO unilaterally appoint a successor through a normal admin screen.

A requester cannot approve the same request. The subject cannot approve their own appointment. Rejection terminates the request. Final approval activates only the exact requested authority and scope, and writes an audit event. Suspension/revocation also writes an audit event.

## Initial database foundation

Laravel primary tables:

- group_authorities: active and historical scoped group authority assignments.
- governance_requests: appointment requests and lifecycle state.
- governance_approvals: approval stages and decisions.

These tables are not exposed as public registration fields. Do not add a public role or is_admin assignment endpoint.

## Implemented Laravel service baseline

The current implementation branch includes a transactional `GovernanceApprovalService` for trusted internal callers. It creates a request with a server-supplied approval plan, prevents requester/subject self-assignment as approvers, requires consecutive approval levels, checks that the assigned approver has the exact active authority and scope, enforces approval order, and activates the requested scoped authority only after all stages approve. Rejection closes the request; expired requests are marked expired; decision and activation events are written to the audit log. Repeated decisions are rejected.

This service is not yet exposed through public or authenticated HTTP routes. The approval plan must be assembled by trusted server-side workflow code, never accepted from a browser as a list of approver IDs. The service has feature tests in the branch, but those tests are not considered verified until the corresponding CI run passes. MFA, approval-management UI, revocation workflow, secure CEO bootstrap command, and active NestJS parity remain outstanding.

## CEO bootstrap

The first CEO identity must be provisioned out-of-band by an authorized deployment operator using a one-time, reviewed bootstrap procedure. Do not create a public bootstrap URL, reusable setup password, default CEO credentials, or seed a known CEO account into production. Record the operator, target account, timestamp, environment, and change reference in the security audit trail. Disable the bootstrap capability after successful initialization.

## Laravel / NestJS parity

Both implementations must enforce the same rules at the service/domain layer, not merely hide UI controls. The NestJS standby must use equivalent authority identifiers, scope semantics, approval states, no-self-approval checks, transactional activation, revocation behavior, and audit event names. Do not treat the archived Node/PostgreSQL reference directory as an active standby or assume its database is synchronized with the Laravel MySQL primary.

## Release gates

Before enabling privileged administration in production:

1. Add MFA and step-up authentication for approvals.
2. Add email verification and controlled invitation/onboarding.
3. Add transactional approval services and authorization policies.
4. Test requester/subject self-approval denial, unauthorized approvers, duplicate decisions, expired requests, rejection, revocation, and concurrent approvals.
5. Confirm MySQL production migration and backup/restore procedure.
6. Implement and test the same contract in an active NestJS standby before claiming failover parity.
7. Obtain security review and an independently controlled CEO bootstrap runbook.


## Group governance staff OTP and approval API

Group-level governance uses a separate allow-list and OTP challenge store; it does not reuse a tenant organisation's staff phone or OTP session. The group allow-list can be managed only by an active `GROUP_CEO` or `GROUP_SECURITY_OFFICER` with group scope `group:nexora`. The target must already hold an active group appointment. Staff cannot add or replace their own group phone.

- `POST /group/staff-security/approved-phones`: pre-register a group appointee's E.164 phone.
- `DELETE /group/staff-security/approved-phones/{id}`: revoke a pre-approved group phone and invalidate outstanding challenges.
- `POST /group/staff-security/otp/send`: send a purpose-bound code to the active approved group phone.
- `POST /group/staff-security/otp/verify`: verify the code and establish a five-minute, user-bound session confirmation.
- `GET /group/governance/approvals`: list only pending approval stages assigned to the signed-in user.
- `POST /group/governance/approvals/{approvalId}/decision`: require the exact purpose `governance.approval.decide.{approvalId}` and consume the verified group OTP before recording a decision.

Group OTP codes are six digits, hashed at rest, expire after five minutes, allow at most five attempts, and are subject to a 60-second resend cooldown. The group SMS provider is configured separately with `NEXORA_GROUP_SMS_URL` and `NEXORA_GROUP_SMS_TOKEN`; HTTPS is mandatory. The primary Docker stack forwards these settings into Laravel.

Tenant organisation staff OTP remains a different flow under `/organization/staff-security`. Trace & Find family OTP and public Nexora account phone verification remain separate again. None of these verifications grants authority in another domain.

The approval API exposes decisions only. Request creation and approval-plan selection remain trusted server-side workflows; clients cannot submit their own approver list or authority chain. A decision requires both assignment to the current approver and the current active authority/scope check performed by `GovernanceApprovalService`.


## Initial CEO bootstrap and authority revocation

The initial \`GROUP_CEO\` appointment is not a public registration flow and cannot be created through the ordinary governance request service. The first appointment is performed once by an authorised deployment operator using the Laravel CLI:

1. Create the intended CEO user account through the controlled account-provisioning process; verify its email and set its account status to \`active\`.
2. Set a temporary high-entropy \`NEXORA_GROUP_CEO_BOOTSTRAP_KEY\` in the protected Laravel runtime environment.
3. From the primary Laravel application directory, run \`php artisan nexora:bootstrap-group-ceo ceo@example.com\` and enter the key at the hidden prompt.
4. Confirm the \`group.ceo.initial_bootstrap\` audit event and active \`GROUP_CEO\` appointment.
5. Remove the bootstrap key from the runtime environment and rebuild/clear cached Laravel configuration. Never keep the bootstrap secret as a permanent application credential.

The command refuses unverified or inactive accounts and refuses to run if any \`GROUP_CEO\` appointment already exists, including a revoked historical appointment. The bootstrap is therefore intentionally non-repeatable; CEO succession requires a separately reviewed, controlled workflow and must not reuse this initial bootstrap command.

Only the active group CEO can revoke a group authority through \`DELETE /group/governance/authorities/{id}\`. The request requires a fresh group OTP whose exact purpose is \`group.authority.revoke.{id}\`. The CEO cannot revoke their own appointment. CEO appointment rows are locked in a stable order during revocation so concurrent requests cannot race the final authority check. Revoking a user's final active group authority also revokes their group-approved phone and invalidates outstanding group OTP challenges. Every successful revocation is audited.

This implementation is part of the Laravel primary. It does not establish NestJS standby parity; the standby must implement and test the same one-time bootstrap, OTP-bound revocation, locking, and audit contract before failover can be considered equivalent.
