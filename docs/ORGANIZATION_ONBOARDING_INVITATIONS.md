# Organization onboarding and invitations

Status: implemented foundation on the Laravel primary, verified by GitHub Actions
CI run 44990809232 (Laravel, NestJS, Next.js, Prisma schema and Docker jobs all
passed). This document describes the server-enforced invitation workflow that
grants organization membership.

## Boundary

Public registration creates a basic account only. It never creates an
organization, membership, role, or administrator. Organization access is granted
only when an authorized member of that organization issues an invitation and the
invitee accepts it with a verified account whose email matches the invitation.

The active organization is always derived from the authenticated session and
re-verified against an active membership. A client-supplied organization ID is
not authorization.

## Roles

Invitable roles are `member`, `manager`, `support`, `billing`, and `admin`.

- An `owner` or `admin` may invite `member`, `manager`, `support`, and `billing`.
- Only an `owner` may invite another `admin`.
- The `owner` role is never invitable. Ownership transfer remains a separate,
  explicitly reviewed workflow.
- Role changes after joining must use a separate authorized administration
  workflow and must be audited.

## Endpoints

| Method | Path | Authentication | Purpose |
|---|---|---|---|
| GET | `/auth/invitations/preview/{token}` | Public, rate-limited | Show invitation metadata and whether it is still usable. |
| GET | `/auth/invitations` | Verified account | List pending invitations addressed to the signed-in email. |
| POST | `/auth/invitations/accept` | Verified account | Accept an invitation. Email must match. |
| POST | `/auth/invitations/decline` | Verified account | Decline an invitation. |
| GET | `/organization/invitations` | Active organization + member manager | List invitations for the active organization. |
| POST | `/organization/invitations` | Active organization + member manager | Create and email an invitation. |
| DELETE | `/organization/invitations/{invitation}` | Active organization + member manager | Revoke a pending invitation. |

The organization endpoints derive the tenant from the server-verified active
organization. An invitation belonging to another organization returns 404 and is
never listed or revoked through the wrong tenant context.

## Lifecycle

`pending` → `accepted` | `declined` | `revoked` | `expired`.

- Tokens are 256-bit random values. Only a SHA-256 hash is stored.
- The raw token is delivered by email. If email delivery fails, the creation
  response returns the token once so an authorized administrator can share it
  through a safe channel. Tokens are never written to audit metadata.
- Tokens are single-use. Accepting, declining, or revoking closes the invitation.
- Expired pending invitations are marked `expired` when a new invitation is
  created for the same email.
- Acceptance is transactional: the membership and invitation state change
  together, and an audit event is written.
- A user who already has any membership row for the organization cannot accept
  an invitation. Reactivation of suspended memberships remains an administrator
  action.

## Security controls

- Server-side policy checks, not hidden UI controls.
- Requester must be an active, verified account.
- Invitee must be an active, verified account with a matching email.
- The organization must be active.
- Duplicate pending invitations and invitations for existing members are
  rejected.
- Rate limits are applied to creation, acceptance, decline, revocation, and
  preview requests.
- Audit events: `organization.invitation_sent`,
  `organization.invitation_accepted`, `organization.invitation_declined`, and
  `organization.invitation_revoked`.

## Configuration

- `NEXORA_INVITATION_TTL_DAYS` controls the invitation lifetime (1–30 days,
  default 7).
- Configure SMTP (`MAIL_*`) before relying on email delivery. CI uses the array
  mail transport and does not prove real delivery.

## Outstanding work

- Authenticated organization administration UI for inviting, resending, and
  revoking members.
- Bulk invitations and domain-level onboarding policy.
- Step-up MFA for owner-level invitations in production.
- Ownership transfer and membership-role-change workflows.
- Email deliverability, bounce handling, and invitation delivery telemetry.
- Rate limiting and abuse controls at the edge in addition to application
  throttles.
