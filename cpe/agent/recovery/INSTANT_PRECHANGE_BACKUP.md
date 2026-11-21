# Mandatory instant pre-change checkpoint gate

## Product requirement

When a router first connects to NetPulse, the agent performs a read-only identity and capability inventory. Before the first requested change—and before every later protected mutation—the system must capture and verify a fresh configuration checkpoint for that router. A connection event alone does not authorise a change.

The required sequence is:

1. Discover the device and bind the session to tenant ID, asset ID, exact hardware fingerprint and configuration schema.
2. Read the current configuration through a supported device adapter.
3. Encrypt the complete supported configuration snapshot with AES-256-GCM using a fresh random nonce and a protected 32-byte key.
4. Persist the encrypted snapshot to durable, tenant-isolated storage.
5. Read the stored object back and verify its SHA-256 digest and authenticated envelope.
6. Record the checkpoint ID, timestamp, device identity, schema, digest, agent version and backup result in the audit trail.
7. Only after all checks succeed may the requested mutation begin.

## Fail-closed behaviour

- Capture failure, partial/incomplete export, unsupported configuration section, encryption error, missing key, storage error, read-back mismatch, identity mismatch, expired session or audit failure blocks the change.
- Do not treat a successful upload request or a client-supplied `backup_complete=true` value as proof of backup.
- A configuration backup is not a firmware image backup and does not prove that firmware rollback is possible.
- Firmware writes require the separate exact-board recovery eligibility gate, verified vendor/original image provenance where applicable, and a tested recovery path.
- If the router is already unreachable or cannot expose a supported configuration export, do not attempt risky remote changes. Offer read-only diagnostics or an external-gateway path.
- Never silently continue with a partial backup. Tell the operator exactly which backup check failed.

## Freshness and concurrency

A checkpoint must be created immediately before each protected change, not merely once at initial connection. Bind the checkpoint to the intended change request and device identity. If another administrator changes the router between capture and apply, invalidate the checkpoint and capture again. Serialize changes per asset to prevent two operations from racing against the same baseline.

## What counts as a protected change

WAN/PPPoE/VLAN, LAN/DHCP, Wi-Fi, firewall, routes, DNS, management/ACS settings, package installation/removal, service restarts that can interrupt management, firmware/bootloader/partition operations, and restore operations.

Read-only health checks and inventory do not require a new checkpoint.

## Implementation status

The Go recovery package now provides `ApplyWithCheckpoint`, a fail-closed orchestration primitive. It captures configuration, encrypts and authenticates the snapshot, asks a durable store to save and read-back verify it, checks the returned snapshot digest, and invokes the mutation callback only after success. Unit tests cover backup-first ordering and failure cases.

This is currently a library-level safety gate with test doubles. The production router adapters, durable storage implementation, key-management integration, server-side audit transaction and all configuration/firmware mutation endpoints must call this gate before they are considered safe. The existing read-only probe does not yet capture router configuration or apply changes. No customer router is changed by this code.
