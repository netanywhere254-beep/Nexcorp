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
