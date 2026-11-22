# NetPulse Firmware Security & Compatibility Agent

## Purpose
The agent is a team-operated module of NetPulse CPE OS and NexISP. It helps determine whether a customer-owned or explicitly authorised router can run NetPulse native firmware, the NetPulse Router Agent, or cloud-only management. It also supports controlled firmware security assessments.

## Operating modes
1. Compatibility discovery (default): collect read-only model, board, SoC, kernel, package manager, flash/RAM, partition metadata, boot method and available network APIs. Redact serial numbers, MAC addresses, Wi-Fi credentials, tokens and public IPs from exported reports.
2. Firmware examination (offline): accept a firmware file supplied by an authorised operator; record cryptographic hashes and file metadata; identify container/filesystem formats; extract into an isolated, non-networked analysis workspace; inventory packages, init scripts, web assets, certificates and configuration defaults; produce a component and attack-surface report.
3. Controlled security assessment: run approved checks only against a device or image explicitly listed in a signed engagement scope. Prefer non-destructive checks, vendor advisories, SBOM/CVE matching, configuration review and isolated-lab validation. Any active test requires a named operator, ticket, target identifier, time window, rate limit and stop conditions.
4. Adaptation feasibility: identify whether supported open firmware or a vendor-supported extension path exists. Produce a feasibility result and recommended path; never assume an arbitrary stock firmware can accept third-party packages.

## Allowed assessment capabilities
- Read-only hardware and firmware identification.
- Offline firmware unpacking, file-system inspection, strings/symbol and package inventory.
- SBOM generation and known-vulnerability matching.
- Static analysis of scripts, binaries and web interfaces.
- Configuration, TLS, exposed-service and update-signature checks.
- Controlled validation of a specific suspected weakness in a lab or explicitly authorised test target.
- Recovery and rollback planning before any write operation.

## Prohibited automation
- No password spraying, credential stuffing, unrestricted brute-force login, or attempts to defeat customer/vendor accounts.
- No exploitation of public or out-of-scope devices.
- No stealth, persistence, botnet behaviour, lateral movement or exfiltration.
- No automatic bootloader unlocking, signature bypass, secure-boot defeat, partition rewriting or firmware flashing.
- No generation or deployment of exploit payloads against live customer equipment.
- No bypass of access controls on third-party equipment without documented owner authorisation.

Where a legitimate recovery or interoperability issue requires credentials, use owner-provided credentials or the vendor's documented recovery process. Credential-strength checks should be offline against an explicitly supplied hash set, with permission and without attempting logins.

## Engagement controls
Every active assessment must have verified customer/owner authorisation; exact target identifiers; approved methods, exclusions, time window and rate limits; named operator and reviewer; a stop button and automatic expiry; immutable audit events and a signed report.

The agent must fail closed if scope is missing, expired, ambiguous or mismatched. Tenant and engagement IDs must be checked server-side on every request to prevent IDOR and cross-tenant access.

## Isolation and safety
Firmware files are untrusted input. Process them in disposable, resource-limited sandboxes with no network by default, read-only source mounts, file-size and decompression limits, timeouts and malware scanning. Never execute extracted firmware binaries on the host. Dynamic analysis, when approved, runs in a separate emulated or physically isolated lab with no route to customer networks or the public internet.

## Output
Each assessment produces device identity and confidence; firmware hash, version and provenance; detected OS, components, packages and exposed interfaces; known-vulnerability matches with evidence and confidence; compatibility matrix for native image, router-agent package, external gateway and cloud-only modes; risk rating, remediation and recovery plan; operator, reviewer, scope and audit references.

## Relationship to the product
The agent is a security and compatibility subsystem, not a magic unlocker. It can tell a customer what is feasible and prepare a safe upgrade path. Native firmware remains model/revision-specific. Program mode works only where the host OS exposes supported APIs and privileges. Stock firmware with closed package management or inaccessible drivers may require an external gateway or cloud-only integration.
