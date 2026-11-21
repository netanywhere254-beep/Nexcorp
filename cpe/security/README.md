# NetPulse firmware security worker

## Current implementation

`firmware_triage.py` is the first local firmware-analysis worker. It performs bounded, offline, read-only triage:

- Requires an engagement scope file and an exact SHA-256 match.
- Rejects expired scopes and scopes without the `static_firmware_triage` permission.
- Enforces a 512 MiB input limit and analyses at most the first 8 MiB for signatures, entropy and readable strings.
- Redacts strings that look like credentials or secrets.
- Emits a JSON report; it never executes or flashes the submitted file.

## Run locally

Use Python 3.10 or later. From this directory:

```sh
python -m unittest -v
sha256sum ./vendor-firmware.bin
```

Create a scope file for the exact file hash. Example only; replace every value with a real, approved engagement:

```json
{
  "engagement_id": "NP-ENG-0001",
  "target": "customer-router-under-approved-test",
  "approved_by": "authorised-security-lead",
  "approval_reference": "approved-ticket-or-contract-reference",
  "expires_at": "2027-01-01T12:00:00+00:00",
  "firmware_sha256": "REPLACE_WITH_EXACT_64_CHARACTER_SHA256",
  "permitted_actions": ["static_firmware_triage"]
}
```

Then run:

```sh
python firmware_triage.py --firmware ./vendor-firmware.bin --scope ./scope.json --output ./triage-report.json
```

The scope file is currently a local workflow guard, not a cryptographically authenticated authorisation. Do not treat it as proof of customer consent. Before production use, NexISP must issue signed, short-lived engagement tokens, verify signatures against a managed trust root, enforce tenant and operator permissions server-side, and retain tamper-evident audit events.

## Planned security modules

1. Static filesystem and partition-layout inspection in a sandboxed worker.
2. Firmware component inventory and software bill of materials.
3. Known-CVE matching against maintained vulnerability feeds.
4. Controlled emulation and dynamic testing in isolated lab networks.
5. Human-reviewed compatibility recommendations and signed release pipeline.

Password guessing, credential harvesting, persistence, stealth, unauthorised access and unattended exploitation are not part of this worker. Any credential-strength testing must be a separate, explicitly approved lab engagement with rate limits, lockout safeguards and documented test accounts.

## Release gate

A triage report is not an installation approval. Never build or flash a device image from a signature match alone. Exact model, hardware revision, board identifier, flash layout, device-tree compatibility, verified image provenance and tested recovery procedure remain mandatory.
