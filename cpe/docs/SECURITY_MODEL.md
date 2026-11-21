# NetPulse CPE security model

## Firmware release trust
- Build artifacts in a controlled, reproducible pipeline.
- Generate SHA-256 digests and sign release manifests with a protected signing key.
- Keep signing keys out of source control and out of router images.
- The release verifier must validate the signature and digest before the eligibility
  service receives trusted verification results.
- Never accept a client-supplied boolean such as `signature_verified=true` as proof.
- Match manufacturer, model, hardware revision, board ID and OpenWrt target exactly.
- Require a verified recovery procedure and an approved release.
- Block unknown, unsupported, retired and lab-only profiles from production installation.

## Device management
- Each router receives unique credentials; no shared fleet-wide CWMP password.
- Use HTTPS/TLS with certificate and hostname validation.
- Do not expose ACS connection-request ports publicly unless strictly required.
- Never store PPPoE, Wi-Fi or ACS secrets in logs or device inventory metadata.
- Record actor, organization, device, artifact digest, result and timestamp for each
  provisioning or firmware action.

## Tenant isolation / IDOR prevention
- Resolve the active organization from authenticated server-side session context.
- Never trust an organization ID supplied in a request body or query string.
- Scope every device query by the active organization before loading or mutating it.
- Use opaque device identifiers; opaque IDs do not replace authorization checks.
- Authorize network-management actions with the organization's server-side policy.
- Test cross-tenant read, update, delete and firmware-assignment attempts.

## Recovery
No automated flashing is enabled by this foundation. Recovery must be documented and
tested on an identical spare unit before a model profile can be promoted to supported.
