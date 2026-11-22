# Account security flows

## Nexora Group staff-number approval (separate workflow)
- An organisation owner/admin with manageMembers permission pre-registers a number for an existing active organisation member.
- Staff cannot self-enrol or replace their approved number.
- Approval and revocation are audit-logged; only the final four digits are stored in audit metadata.
- Staff OTP delivery uses NEXORA_STAFF_SMS_URL and NEXORA_STAFF_SMS_TOKEN, independently of Loopza Trace & Find.
- Codes are six digits, hashed at rest, expire after five minutes, allow five attempts, and are resend-throttled.
- A verified challenge is bound to an organisation and purpose. Sensitive action handlers must call StaffOtpConfirmation consume with the exact action purpose before performing the action. It consumes the challenge once; verification alone is not authorisation.

## Nexora public account phone verification (separate workflow)
- Ordinary users can submit and verify their own contact number during onboarding; this is not a staff approval flow.
- The account phone OTP uses NEXORA_PUBLIC_SMS_URL and NEXORA_PUBLIC_SMS_TOKEN, separate from staff and Trace & Find providers.
- Codes are six digits, hashed at rest, expire after five minutes, allow five attempts, and are resend-throttled.
- Verified numbers are unique across public accounts. Challenges are bound to the requesting user and phone.
- If a public account changes its phone, its Trace & Find phone-verification status is reset; account verification never grants Trace & Find case eligibility by itself.
- Trace & Find may independently update the user's contact phone. The public-account verification record is only considered current when it matches the user's current phone.

## Loopza Trace & Find family verification (separate workflow)
- Family/public users register and verify their own phone through Trace & Find OTP endpoints.
- Verified contact is required before submitting a missing-person case.
- This verification does not grant staff privileges and is not shared with the Nexora staff allow-list.

## Organization onboarding invitations
- Organization access is granted only through an invitation issued by an active
  owner or administrator of that organization and accepted by a verified account
  whose email matches the invitation.
- Only an owner may invite another administrator. The owner role cannot be
  granted by invitation; ownership transfer is a separate reviewed workflow.
- Invitation tokens are 256-bit values stored only as SHA-256 hashes. They are
  single-use, expire after the configured window, and are never written to audit
  metadata.
- Acceptance is transactional and writes an audit event. Existing memberships
  cannot be overwritten by an invitation.
- The organization is derived from the server-verified active organization; an
  invitation from another tenant is not visible or revocable through the wrong
  context.
- See [ORGANIZATION_ONBOARDING_INVITATIONS.md](ORGANIZATION_ONBOARDING_INVITATIONS.md).

## Delivery configuration
Configure all three HTTPS SMS providers independently using deployment secret storage. Blank provider configuration disables sending. CI passing does not prove live SMS delivery.

Public registration remains basic-user-only. Email verification and password recovery use signed links and hashed reset tokens; configure SMTP before launch.
