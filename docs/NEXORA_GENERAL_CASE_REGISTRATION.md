# Nexora Group general case registration

Nexora Group case registration is separate from Loopza Trace & Find.

## Reference format

- Nexora Group: \`NEX-YYYY-000001\`
- Loopza Trace & Find: \`LTF-YYYY-000001\`

The Nexora endpoint uses the \`nexora\` sequence in \`App\Services\CaseReferenceNumber\`. It does not create or modify Loopza records.

## API

- \`POST /nexora/cases\` — register a case.
- \`GET /nexora/cases\` — list only the signed-in user's cases.

Registration requires an authenticated account with verified email and a verified account phone. Phone verification is performed through the separate account phone OTP flow at \`/account/phone/send-code\` and \`/account/phone/verify-code\`.

Supported case types: complaint, support_request, service_request, security_incident, other. Cases begin in \`submitted\` status and remain private.

## Privacy and audit

Case titles and descriptions use Laravel encrypted casts and are stored encrypted at rest. The list endpoint is scoped to the authenticated reporter. A registration audit event records the reference, type, and priority without copying the case narrative into audit metadata.

This is the public reporter registration foundation. A separately authorised group review queue, assignment workflow, staff-only OTP gates, and case lifecycle actions must be implemented before operational staff can process these records.


## Staff review boundary

The public reporter endpoints do not expose a case queue, assignment, status mutation, or cross-user case lookup. Those operations must be added behind explicit group-scoped permissions and staff OTP verification. Do not reuse Loopza investigator or authority roles to grant Nexora Group case access.
