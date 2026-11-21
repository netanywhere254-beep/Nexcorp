# Offline configuration restore planning

The restore planner is an offline dry-run component. It consumes an authenticated checkpoint's metadata, current and proposed canonical configuration documents, a device profile and a time-limited approval record.

## Canonical configuration format

The current planner accepts JSON with:
- `schema_version`: exact schema identifier matching checkpoint metadata.
- `sections`: one or more of `wan`, `lan`, `wireless`, `dhcp`, `firewall`, `routes`, `dns`, and `management`.

It rejects unknown top-level fields, unknown section names, malformed JSON, empty documents and schema mismatches. Device-specific semantic validation is not implemented yet; this format check is not proof that a router can accept the settings.

## Eligibility checks

A plan is rejected unless:
- Exact manufacturer, model, hardware revision and board ID are present.
- The registry profile says configuration restore is verified and enabled.
- Support level is R1 or higher and profile status is `supported` or `lab_only`.
- Lab evidence reference exists and its test date is no more than 365 days old.
- Approval is active, explicitly approved, matches tenant and asset, has an authorisation reference, and names separate operator and reviewer identities.
- Both configurations match the checkpoint's schema version.

The planner returns changed section names and SHA-256 digests only. It does not return configuration values, secrets or raw settings.

## Safety boundary

Every returned plan has `execution_enabled=false` and status `dry_run_ready_for_lab_review`. No router connection, API call, command execution, restore, firmware write or rollback occurs. A later device-specific adapter must add schema validation, capability checks, signed server-side approval, audit persistence, pre-restore checkpointing, maintenance-window checks, and tested rollback before any execution can be considered.
