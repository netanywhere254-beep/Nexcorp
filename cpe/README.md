# NetPulse CPE OS

Multi-vendor CPE firmware and management foundation for NexISP.

## Scope

- OpenWrt-based firmware builds only for exact, verified hardware targets.
- A shared device registry and firmware eligibility policy.
- TR-069/CWMP integration through a replaceable client adapter.
- GenieACS as the initial ACS integration candidate.
- A future Windows/Linux Firmware Manager for discovery, image verification,
  installation orchestration and recovery guidance.

## Safety status

This repository does **not** yet contain a flashable Huawei WS318n image.
The reported WS318n firmware is 10.0.2.6, but its hardware revision, board ID,
SoC, flash geometry, partition map and recovery path are not yet verified.
The Huawei profile therefore remains blocked.

Never infer compatibility from a marketing model name alone. Exact board ID,
hardware revision, flash layout, image format and recovery procedure must match.

## Development stages

1. Capture read-only hardware inventory for the exact router.
2. Confirm whether the exact board is supported by a maintained OpenWrt target.
3. If unsupported, evaluate a documented device port; do not guess DTS or partitions.
4. Select and test a maintained CWMP client against a lab ACS.
5. Implement per-device credentials, TLS validation and parameter allow-lists.
6. Add signed firmware artifacts and a reproducible build pipeline.
7. Test recovery on a spare identical device before any customer deployment.

## Directory guide

- `registry/`: device profile schema and initial model records.
- `security/`: fail-closed firmware eligibility logic.
- `docs/`: architecture, build gates and lab test plan.

## Future desktop program

The Firmware Manager is planned as a separate desktop application. It will:
- identify connected routers using supported, read-only methods;
- match the exact hardware profile;
- verify image signature, checksum, target and size;
- refuse unknown or mismatched devices;
- guide users through vendor-supported installation/recovery;
- record device and firmware history.

It will not attempt undocumented bootloader exploits or blind flash writes.
