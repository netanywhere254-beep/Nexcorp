# Loopza Trace & Find human review console

This is an internal Nexora Group review workflow, not a public authority/investigator portal.

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

- Review access is group-scoped and does not use a user's public family account as reviewer authority.
- All sensitive read and write actions require a fresh OTP with a route-specific purpose.
- Profile approval requires all three checks (identity, location evidence, selfie) to be approved. A submitted coordinate is not proof of physical presence; the reviewer must assess the evidence and may reject it.
- Case approval changes status to \`approved_pending_publication\`; it does not publish the case. Publication is a separate action requiring a future, separately authorised workflow.
- Review decisions are audited without storing private notes or identity images in audit metadata.
- Rejected profiles and cases remain private. This first review workflow does not yet provide resubmission, external authority account provisioning, investigator assignments, volunteer access, or case publication.
