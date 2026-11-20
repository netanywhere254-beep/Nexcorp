# Loopza Trace & Find — Vetted Access Applications

## Separate account and review process

Family onboarding remains separate from professional access. Authority, professional investigator and volunteer applicants use:

- \`POST /trace-find/access-applications\`

Accepted \`requested_role\` values are \`authority\`, \`professional_investigator\` and \`volunteer\`. Every application requires a private evidence file (PDF/JPG/PNG, maximum 10 MB) and a two-letter country code. Authority and professional investigator applications additionally require the organisation name, registration reference and a credentials summary.

Applications are stored as \`pending_review\`. The upload is stored on the private local disk. Public onboarding cannot create a group authority appointment or mark an applicant verified.

## Restricted human vetting

Only an active Nexora Group Security Officer or Group CEO appointment can use the review endpoints. Each sensitive operation requires its own one-time, organisation-preapproved group staff OTP:

- \`GET /trace-find/review/access-applications\` — purpose \`loopza.trace.access_applications.queue\`
- \`GET /trace-find/review/access-applications/{id}/evidence\` — purpose \`loopza.trace.access_applications.evidence.{id}\`
- \`POST /trace-find/review/access-applications/{id}/decision\` — purpose \`loopza.trace.access_applications.decision.{id}\`

Decisions are \`approve\`, \`reject\` or \`request_information\`, with required reviewer notes. Evidence reads and decisions are audited. Review decisions are transactional.

## Permission boundary

Approval records that the application passed this vetting step for the stated role and country. It does **not** create a Nexora Group Authority appointment and does not automatically grant access to private cases, case documents, identity evidence, or cross-border records. Those permissions require separate explicit, country-scoped policy grants and audit controls. Volunteers must remain least-privilege and cannot browse restricted case records by default.

## Operational notes

- Verify the issuing organisation and registration independently using permitted official sources or authorised channels.
- Verify professional credentials, jurisdiction and validity dates before approval.
- Record the evidence and reasoning in reviewer notes without copying unnecessary sensitive data.
- Use \`needs_more_information\` to request corrections; do not approve incomplete evidence.
- Keep uploaded evidence private and apply retention/deletion rules under the applicable country policy.

## Separate country-scoped access grants

A successful vetting decision is not an access grant. An authorised Nexora Group Security Officer or Group CEO must separately call:

- `POST /trace-find/review/access-applications/{applicationId}/grant`

The grant action requires a fresh one-time group staff OTP for purpose
`loopza.trace.access_applications.grant.{applicationId}`. It creates a
`trace_country_access_grants` record tied to the approved application, with:

- the applicant and vetted role;
- the application's country code (the reviewer cannot substitute another country);
- an optional narrower region code;
- an explicit role-allow-listed permission set;
- an expiry time, grantor and active/revoked status.

Authority permissions, investigator permissions and volunteer permissions
are separate allow-lists. Volunteers cannot be granted private case or document
permissions through this endpoint. Grants are audited. Runtime permission enforcement is applied by the authorised case and document endpoints. An active grant can be revoked by an authorised Nexora Group Security Officer or Group CEO using a fresh OTP:

- `POST /trace-find/review/access-applications/grants/{grantId}/revoke` — requires a reason and OTP purpose `loopza.trace.country_access.revoke.{grantId}`. Revocation is transactional, records `revoked_at`, and writes an audit event.



## Runtime country-grant enforcement

Vetted users access the external discovery surface through:

- \`GET /trace-find/authorised/cases\` — returns metadata for published cases only.
- \`GET /trace-find/authorised/cases/{caseId}/documents/{documentId}\` — returns a verified document attached to a published case only.

Every request checks that the grant is active, unexpired, belongs to the authenticated user, matches the approved application role and country, includes the requested permission, and matches the case country and optional region. The source application must remain approved. Role permission allow-lists are rechecked at runtime; a volunteer grant cannot be used for private case access even if a malformed grant row exists.

Unpublished/private cases and unverified documents are not exposed through these endpoints. Reads are audited. A grant is not a publication decision and does not bypass Nexora's internal human review.
