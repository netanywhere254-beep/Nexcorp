# NetPulse firmware security worker

## Current implementation

`firmware_triage.py` is the first local firmware-analysis worker. It performs bounded, offline, read-only triage:

- Requires an approved engagement scope, exact tenant asset ID and exact firmware SHA-256 match.
- Rejects pending/revoked scopes, scopes outside their time window, mismatched assets or hashes, and scopes without `static_analysis` permission.
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
  "tenant_id": "tenant-identifier",
  "asset_owner": "customer-or-asset-owner",
  "targets": [{
    "asset_id": "router-asset-001",
    "manufacturer": "Manufacturer",
    "model": "Exact model",
    "hardware_revision": "Exact revision",
    "firmware_hash_sha256": "REPLACE_WITH_EXACT_64_CHARACTER_SHA256"
  }],
  "authorisation_reference": "approved-ticket-or-contract-reference",
  "operator_id": "named-operator",
  "reviewer_id": "independent-reviewer",
  "starts_at": "2026-11-21T00:00:00+00:00",
  "stop_conditions": ["unexpected service impact", "scope mismatch"],
  "approval_status": "approved",
  "expires_at": "2027-01-01T12:00:00+00:00",
  "allowed_methods": ["static_analysis"]
}
```

Then run:

```sh
python firmware_triage.py --firmware ./vendor-firmware.bin --scope ./scope.json --asset-id router-asset-001 --output ./triage-report.json
```

The scope file is currently a local workflow guard, not a cryptographically authenticated authorisation. Do not treat it as proof of customer consent. The current worker validates the approved status, time window, tenant, exact asset ID and exact firmware hash. Before production use, NexISP must issue signed, short-lived engagement tokens, verify signatures against a managed trust root, enforce tenant and operator permissions server-side, and retain tamper-evident audit events.

## Planned security modules

1. Static filesystem and partition-layout inspection in a sandboxed worker.
2. Firmware component inventory and software bill of materials.
3. Known-CVE matching against maintained vulnerability feeds.
4. Controlled emulation and dynamic testing in isolated lab networks.
5. Human-reviewed compatibility recommendations and signed release pipeline.

Password guessing, credential harvesting, persistence, stealth, unauthorised access and unattended exploitation are not part of this worker. Any credential-strength testing must be a separate, explicitly approved lab engagement with rate limits, lockout safeguards and documented test accounts.

## Release gate

A triage report is not an installation approval. Never build or flash a device image from a signature match alone. Exact model, hardware revision, board identifier, flash layout, device-tree compatibility, verified image provenance and tested recovery procedure remain mandatory.
