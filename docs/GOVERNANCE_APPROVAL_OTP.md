# Nexora governance approvals with pre-approved phone OTP

## Purpose

Governance approvals require a second confirmation factor delivered only to a phone number that has been pre-registered, independently verified, and approved for the assigned approver. The ordinary account contact phone (`users.phone`) may be entered during public registration or normal onboarding. It is not an approval factor and is never used for governance OTP delivery. Staff and management approval phones are a separate credential: they must be pre-registered, independently verified, and approved by the organisation. Staff cannot self-enrol or replace this approval phone.

## Approval flow

1. A governance request is created with one or more approval records assigned to named approver accounts and approval levels.
2. The assigned approver signs in and requests an OTP for their assigned pending approval. Their ordinary account contact phone is deliberately ignored by this flow.
3. The platform looks up an active, verified authorized phone record for that approver. It never accepts a destination number from the HTTP request.
4. A six-digit OTP is generated, stored only as a password hash, and delivered through the configured SMS gateway.
5. The approver submits the OTP with an approved or rejected decision.
6. The OTP is consumed exactly once. Wrong attempts are counted and capped at five; the code expires after the configured TTL.
7. The approval and parent request are updated and an audit event is written. A requester cannot approve their own request.

## Phone provisioning

Phone provisioning is intentionally not exposed as a public or ordinary user API. Ordinary account phone entry remains available during registration and onboarding, but it does not create an authorised approval-phone record. Only a trusted server operator can run:

    php artisan nexora:approval-phone:add USER_ID +2547XXXXXXXX --approved-by=CEO_USER_ID

The command requires the approved-by account to hold an active Nexora CEO or SECURITY_ADMIN group authority. The operator must confirm that the number has been independently verified and approved for the named person. The number is stored encrypted; the provisioning approver must be a separate active CEO or SECURITY_ADMIN account (not the target staff member). an HMAC-SHA-256 digest keyed with the application key supports uniqueness checks. A trusted operator can revoke a number with `php artisan nexora:approval-phone:revoke +2547XXXXXXXX --approved-by=CEO_USER_ID`. Both provisioning and revocation are audited. A dedicated dual-control management screen is still a follow-up item.

## SMS gateway configuration

Set these secrets in the primary deployment environment; never commit real values:

- APPROVAL_SMS_URL
- APPROVAL_SMS_TOKEN
- APPROVAL_SMS_SENDER_ID
- APPROVAL_OTP_TTL_MINUTES (default 5)

The gateway must accept a JSON POST containing to, message, reference, and sender_id, with a bearer token. Adapt the service request mapping to the chosen provider before production. The service fails closed if credentials are absent or delivery fails. CI uses a fake HTTP endpoint; that does not prove real SMS delivery.

## Endpoints

- POST /governance/approvals/{approval}/otp — authenticated assigned approver requests delivery to their pre-approved phone.
- POST /governance/approvals/{approval}/confirm — authenticated assigned approver submits otp, decision (approved or rejected), and optional comment.

Both endpoints are rate-limited. Phone values and OTPs are never returned in API responses or written to audit metadata.

## Production requirements still outstanding

- Select and contract an SMS provider, configure secrets, and perform delivery/receipt tests.
- Add a dual-control phone provisioning and revocation screen with re-verification on every number change.
- Review retention, incident response, provider data processing, and backup/restore handling.
- Apply deployment-specific throttling and alerting. The code's OTP attempt cap is enforced per challenge; edge-level abuse controls should also be enabled.
