# Loopza Trace & Find — location-first, selfie-second onboarding

## Sequence

1. The authenticated user submits country, region, optional locality, device coordinates, and explicit location consent to `POST /trace-find/profile`.
2. The platform records the location submission and consent timestamps. The profile remains pending; location is not automatically marked verified.
3. The user then submits a selfie and explicit biometric-processing consent to `POST /trace-find/profile/selfie`.
4. The platform stores the image on the private local disk and records the selfie submission and consent timestamps.
5. Both verification statuses remain pending until an authorised review process is implemented and a reviewer records a decision.

## Security boundaries

- A selfie cannot be submitted before a location submission exists.
- A second selfie submission is rejected; replacement requires a separately authorised workflow.
- The public onboarding path creates family profiles only. It cannot create authority, investigator, volunteer, or organisation accounts.
- The selfie is stored on the private `local` disk, not a public web directory.
- The two timestamps make the required order auditable.
- Location coordinates are user/device-provided signals. They can be inaccurate or spoofed and are not proof of identity, residence, or physical presence.
- Selfie upload is not facial recognition or proof of identity. No automated identity decision is made by this change.
- Case creation must continue to require the separately verified phone and any additional case permissions.

## Required before production

An authorised, audited human-review workflow must be built to review location and selfie submissions, record reviewer identity, decision, reason and time, and handle rejection, appeal, retention and deletion. Until then, profiles remain pending and must not be treated as locally verified.
