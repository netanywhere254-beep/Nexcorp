# NetPulse configuration checkpoint package

This package provides an offline encrypted checkpoint format for the NetPulse CPE recovery workflow.

## What it does

- Encrypts configuration bytes using AES-256-GCM from Go's standard library.
- Authenticates tenant ID, asset ID, hardware fingerprint, schema version and creation time as associated data.
- Uses a fresh cryptographically random nonce for every snapshot.
- Includes a SHA-256 digest of the plaintext configuration inside the authenticated payload.
- Refuses to decode a snapshot for a different tenant, asset, hardware fingerprint or configuration schema.
- Limits configuration payloads to 8 MiB.
- Never prints configuration data or keys.

## API

- `EncodeSnapshot(metadata, configuration, key)` creates an encrypted JSON envelope.
- `DecodeSnapshot(snapshot, key, expectedMetadata)` authenticates, decrypts and returns configuration bytes and metadata.

The returned bytes are **not applied to a router**. A future restore adapter must independently validate the configuration schema, device-specific constraints, approval scope and recovery readiness before it can change device state.

## Key handling requirements

The caller must provide a uniformly random 32-byte key. Do not hard-code it, commit it, put it in source control, pass it in command-line arguments, or log it. Production must obtain keys from a managed KMS/HSM or an approved OS secret store with tenant-scoped access, rotation, revocation and audit. A local environment variable may be used only for isolated development tests; it is not a production key-management design.

Store snapshot files with restrictive permissions (owner read/write only), encrypted storage, tenant-separated paths and retention limits. Use atomic file replacement and verify file ownership/permissions in the eventual storage adapter. Protect the key separately from the snapshot.

## Current safety boundary

This is a format and validation library, not a router restore tool. It does not connect to routers, change settings, flash firmware, execute vendor commands, or prove that a device has a working recovery path. Never interpret a successful decrypt as proof that a configuration is compatible with a target device. Huawei WS318n remains firmware-write blocked until exact hardware identity and a lab-tested recovery procedure are recorded.
