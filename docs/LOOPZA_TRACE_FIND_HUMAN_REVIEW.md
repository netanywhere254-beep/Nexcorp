# Loopza Trace & Find human review console

This is an internal Nexora Group review workflow, not a public authority/investigator portal. Public family onboarding is deliberately sequential: first the user records their location and consents to location processing; only then can they submit a selfie with separate biometric-processing consent.

## Reviewer access

Only an active Nexora Group Security Officer or Group CEO appointment scoped to \`group/nexora\` can enter the review endpoints. Every queue read, private selfie view, and review decision consumes a fresh one-time Nexora Group staff OTP. The OTP must be sent to a phone number pre-approved for that staff account.

The IT Manager technical role is not included. This prevents technical administration access from implicitly granting access to sensitive identity or missing-person case content.

## Routes

- \`GET /trace-find/review/profiles\` — pending family profile verification queue.
- \`GET /trace-find/review/profiles/{id}/selfie\` — private selfie evidence.
- \`POST /trace-find/review/profiles/{id}/decision\` — record identity, location and selfie decisions.
- \`GET /trace-find/review/cases\` — private case review queue.
- \`POST /trace-find/review/cases/{id}/decision\` — approve for later publication, reject, or request more information.

Send a code through \`POST /group/staff-security/otp/send\` with the exact purpose for the intended action, then verify it through \`POST /group/staff-security/otp/verify\`.

## Important safeguards

- Reviewer eligibility is group-scoped, but all Trace & Find data access is additionally country-scoped. A group appointment alone grants no country access; separate active country appointments are required.
- Cross-country object identifiers cannot bypass the country boundary: private profile, case, document, application and grant lookups are constrained in the database query, returning not found for records outside the reviewer's appointed countries.
- Country scope is checked again inside transactional decision/grant/revocation operations to prevent a concurrent scope or record change from bypassing the boundary.
- Reviewer country appointments do not grant authority, investigator or volunteer permissions to the reviewed applicant; those remain separately vetted and explicitly granted.
- All sensitive read and write actions require a fresh OTP with a route-specific purpose. Queue reads, private selfie access and restricted case-document downloads are also recorded in the audit log.
- Profile approval requires all three checks (identity, location evidence, selfie) to be approved. Location consent, selfie submission time and biometric-processing consent time are recorded separately; the selfie endpoint refuses requests before the location step. A submitted coordinate is not proof of physical presence; the reviewer must assess the evidence and may reject it.
- Case approval changes status to \`approved_pending_publication\`; it does not publish the case. Publication is a separate action requiring a future, separately authorised workflow.
- Review decisions are audited without storing private notes or identity images in audit metadata.
- A case can be resubmitted only by its original reporter after `rejected` or `needs_more_information`. The same case reference is retained; the case returns to `pending_review` and remains private. Each resubmission stores a snapshot of the prior review state and reason in `trace_case_resubmissions`; existing evidence is retained and new documents are appended as supplementary evidence.
- Rejected profiles do not yet have a controlled resubmission workflow. External authority account provisioning, investigator assignments, volunteer access, and public case publication remain separate future workflows.


## Human review console

Authenticated, email-verified Nexora Group reviewers can open the Laravel review workspace at:

- GET /trace-find/review/console

The workspace provides separate queues for family profiles, Loopza cases, and vetted access applications. It requests a fresh purpose-specific Group Staff OTP before each queue read, private selfie/evidence/document download, and review decision. The corresponding API controllers remain the security boundary; hiding a UI control is never treated as authorisation.

- Family profile decisions remain separate for identity, location and selfie.
- Case approval leaves publication status private; publication remains a separate workflow.
- Access application approval records vetting only and does not create authority appointments or grant private case access.
- Sensitive evidence downloads are streamed from private local storage and audited.
- The queue exposes only document metadata; storage paths are never returned to the browser.
