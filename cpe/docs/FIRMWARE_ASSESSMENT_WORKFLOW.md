# Firmware Assessment Workflow

## Lifecycle
1. Register device: create a tenant-scoped device record; capture manufacturer, exact model, hardware revision, board ID, firmware build and owner.
2. Verify authority: attach customer consent or internal asset-owner approval. Record permitted targets, test window, allowed techniques and exclusions.
3. Discover: run read-only inventory. If model/revision cannot be identified confidently, mark the device UNKNOWN — NO FLASH / NO ACTIVE TEST.
4. Acquire firmware: obtain an image from the vendor or owner. Record source, timestamp, version, file size and SHA-256.
5. Analyse offline: inspect format, partitions, filesystems, package inventory, update mechanism, certificates, default configuration and web/API surface in a sandbox.
6. Triage: map components to vendor advisories and CVEs; distinguish confirmed findings from version-based possible matches.
7. Validate: only if explicitly authorised, validate a finding in an isolated lab or exact in-scope device. No unrestricted credential brute force.
8. Decide: classify as supported native port, supported agent install, external gateway, cloud-only, or unsupported.
9. Release: require peer review, reproducible build, image signature, hash, compatibility manifest, recovery instructions and staged rollout.
10. Close: revoke temporary access, preserve audit evidence, provide customer report and delete working copies according to retention policy.

## Required gates
- Unknown board/revision blocks firmware installation.
- A mismatched target or partition layout blocks image generation and flashing.
- Missing authorisation blocks active testing.
- Missing backup/recovery procedure blocks write operations.
- Failed signature or hash verification blocks release.
- Unreviewed firmware cannot be offered to customers.
- Production rollout requires canary deployment, health checks and automatic rollback criteria.

## Initial supported tools (subject to licensing and validation)
- binwalk for firmware format and filesystem discovery.
- file, strings, xxd and filesystem-specific read-only tools for inspection.
- syft for SBOM generation where supported.
- grype or an equivalent scanner for package vulnerability matching.
- semgrep for supported source/script static analysis.
- QEMU or vendor-provided emulators for isolated dynamic analysis where the architecture and image support it.

Tools must run inside disposable, resource-limited workers. Pin versions, record tool hashes and retain machine-readable output. Do not install analysis tooling on customer routers.

## Report confidence
- Confirmed: directly observed and validated with evidence.
- Probable: strong version/component match, but not validated on this exact build.
- Possible: heuristic match requiring manual review.
- Not assessed: insufficient data or out of scope.

Never present a CVE version match alone as proof of exploitability.
