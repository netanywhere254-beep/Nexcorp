# NetPulse CPE OS — Rollback and Recovery Architecture

## Product requirement

Every supported deployment must present an explicit recovery plan before changing router state. The user/operator must be able to choose an available recovery path and understand whether it is automatic, local, or requires physical access. No device may be labelled rollback-capable until its exact hardware and recovery path have been validated.

## Recovery modes

1. **Configuration checkpoint** — export and validate router configuration, service settings, firewall/routing state and NetPulse agent configuration before changes. Encrypt backups, bind them to tenant + asset + hardware identity, and support restore with schema/version validation.
2. **A/B firmware slots** — on hardware that genuinely supports dual boot slots, write the inactive slot, verify its image, boot it as a trial, and confirm health before marking it permanent. If health checks fail or confirmation times out, boot the known-good slot. Never assume A/B support based on marketing or model name.
3. **Bootloader / vendor recovery** — document the exact vendor recovery method for the precise model and hardware revision. This may require TFTP, USB, serial console, recovery button sequence, or manufacturer utility. Instructions must be model-specific and lab-tested.
4. **External gateway fallback** — if a router cannot safely be modified or restored remotely, retain its original firmware and use a supported NetPulse gateway in front of it or in bridge/AP topology.
5. **Cloud management fallback** — if local agent fails but router networking remains functional, use supported TR-069/USP/vendor management to restore the prior configuration. Do not assume remote recovery when WAN, power, boot or management connectivity has failed.
6. **Factory reset / vendor image restore** — last-resort option only after warning that factory reset may erase credentials, ISP settings, VoIP configuration, calibration data or custom provisioning. Require a verified, exact-model vendor image and documented re-provisioning path.

## Mandatory pre-change checkpoint

Before a firmware write, package install, network reconfiguration or security test that can alter device state:

- identify manufacturer, exact model, hardware revision, board ID, SoC, flash/RAM, partition map and bootloader;
- record current firmware version/build and cryptographic hash where available;
- export current configuration and redact secrets from diagnostic copies;
- capture WAN mode, VLAN/PPPoE settings, LAN/DHCP, Wi-Fi, firewall, routes, DNS, TR-069/USP endpoint and device-specific settings;
- preserve original firmware only where acquisition is lawful and technically supported; verify file integrity and provenance;
- confirm a recovery path, required access, power stability and a tested spare/identical lab unit;
- test that backup artifacts can be read and restored;
- record operator, reviewer, customer approval, maintenance window and stop conditions;
- display a recovery readiness result: READY, CONDITIONAL, or BLOCKED.

A successful backup alone does not mean the device can be recovered. The system must separately establish that a tested restore mechanism exists.

## Transactional firmware update lifecycle

1. Preflight: exact board profile, image signature/hash, size, partition compatibility, power and storage checks.
2. Snapshot: create encrypted configuration checkpoint and firmware provenance record.
3. Stage: write only the verified inactive slot or approved staging area; never overwrite the sole known-good boot image unless the vendor procedure explicitly requires it and recovery is proven.
4. Verify: read back where supported, verify signature/hash and boot metadata.
5. Trial boot: boot new image with watchdog/bootcount and a bounded confirmation window.
6. Health gate: check boot completion, storage, agent heartbeat, WAN reachability, management channel, routing, DNS, DHCP, firewall and radio availability where applicable.
7. Commit: mark new slot permanent only after all required checks pass and the configured confirmation policy is satisfied.
8. Rollback: on failed boot, watchdog expiry, failed health gate or operator abort, return to known-good slot/configuration where hardware permits.
9. Reconcile: report outcome to NexISP; if disconnected, persist signed local event and sync later.

## Recovery readiness and UI choices

Show only methods proven for the exact device profile:

- Restore previous configuration
- Revert to previous firmware slot
- Reinstall verified original vendor firmware
- Enter vendor recovery procedure
- Switch to external NetPulse gateway
- Factory reset and re-provision (last resort)

For every option show: prerequisites, data/settings that may be lost, whether remote or physical access is required, expected outage risk, tested date, tested hardware revision and recovery instructions.

## Failure and safety rules

- Do not promise rollback on single-slot devices.
- Do not remotely flash a device when power is unstable or recovery access is absent.
- Never use a firmware image for a different board revision, even if the retail model name matches.
- Never erase bootloader, calibration, ART/radio data, factory partitions or device identity partitions as part of routine installation.
- Never automatically factory-reset a customer's router.
- A failed or uncertain recovery must stop further changes and raise a priority incident.
- Customer traffic continuity takes priority: fail over to a pre-provisioned backup gateway where available.
- Keep recovery artifacts encrypted, access-controlled, tenant-isolated, integrity-checked and retention-limited.
- Record immutable audit events for snapshot, approval, stage, boot, health gate, rollback and restore.

## Device support levels

- **R0 — No verified recovery:** no firmware write permitted; agent/cloud-only or external gateway only.
- **R1 — Configuration restore:** safe configuration backup/restore only; firmware replacement disabled.
- **R2 — Vendor recovery:** exact vendor recovery process documented and lab-tested; firmware replacement requires local/physical operator.
- **R3 — A/B rollback:** dual-slot trial boot and automatic rollback validated on exact board revision.
- **R4 — Fleet recovery:** R3 plus tested backup gateway/failover, remote orchestration and offline recovery queue.

The registry must store recovery level per exact hardware revision, not per marketing model.

## Current project limitation

This document defines the target design. The current NetPulse agent and firmware triage worker do not yet implement firmware flashing, A/B slot control, vendor recovery, or automated rollback. Do not use them to modify customer routers. The Huawei WS318n profile remains blocked until exact hardware identity and a tested recovery method are established.
