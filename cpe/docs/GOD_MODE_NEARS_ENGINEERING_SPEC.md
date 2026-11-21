# GOD MODE and NEARS — Engineering Control Plane

**Product scope:** NetPulse CPE OS, NetPulse Firmware Manager, NexISP Cloud  
**Status:** Architecture contract; implementation and hardware validation are still required.

## 1. Objective

Provide authorised engineers with a consistent privileged workflow and a separately controlled emergency recovery path. GOD MODE is an authenticated engineering session, not a hidden account or access-control bypass. NEARS (NetPulse Emergency Access & Recovery System) permits narrowly scoped local recovery when NexISP or the normal management channel is unavailable.

This specification extends the existing firmware assessment, mandatory checkpoint, and R0–R4 recovery architecture. It does not authorise firmware flashing on an unverified device.

## 2. Trust boundaries and components

- **NexISP Identity & Policy:** provisions engineer identities, roles, device/tenant scope, approvals, credential issuance and revocation.
- **CPE Local Security Agent:** verifies signed offline credentials, enforces local operation policy, manages session expiry and writes tamper-evident local audit events.
- **Firmware Manager:** provides the engineer interface and orchestrates discovery, preflight, checkpoint and supported recovery adapters.
- **Recovery Capability Registry:** records tested recovery level per exact manufacturer, model, hardware revision and board ID.
- **Audit Synchroniser:** uploads signed local events after connectivity returns; it must not silently overwrite or discard local records.

The cloud service is not trusted to waive device-side safety gates. The local agent is not trusted to expand a credential's scope.

## 3. GOD MODE activation contract

Engineer command: `GOD MODE ACTIVATE`

Activation is accepted only when all checks succeed:

1. Engineer identity is authenticated with MFA; use hardware-backed/passkey authentication where available.
2. The assigned engineering role and individual permissions allow the requested operation class.
3. The target device identity is known and bound to the session.
4. Tenant, asset, engagement and maintenance-window scope are valid.
5. The policy service confirms the session is not revoked.
6. A supported configuration checkpoint is captured, encrypted, read back and integrity-verified before any state-changing operation.
7. Recovery readiness is evaluated independently from backup success.
8. A session ID, expiry, policy version and audit chain are created.

The activation phrase is only a command selector. It is not a password, secret, credential, or authentication factor. Do not expose a public unauthenticated activation endpoint.

Routine read-only diagnostics can run without additional prompts. State-changing actions may run automatically only when the signed policy explicitly permits them and all preconditions pass.

## 4. NEARS emergency activation contract

Engineer command: `NEARS EMERGENCY ACTIVATE`

NEARS is used when the normal cloud or management path is unavailable. It requires a previously issued, digitally signed emergency credential. The credential is bound to a named engineer, tenant, exact asset or explicitly bounded asset set, permitted operation classes, validity interval, policy version and unique credential ID.

The local verifier must:

- verify the signature against a pinned trust root and an allowed key ID;
- reject unknown algorithms, keys, malformed claims, expired credentials and not-yet-valid credentials;
- check exact tenant and device binding;
- enforce permitted operations and maximum session duration;
- reject credentials locally marked revoked or superseded;
- maintain monotonic anti-replay/session counters where hardware permits;
- fail closed if trustworthy time or required anti-replay state cannot be established;
- persist signed audit events before acknowledging privileged actions.

Offline access is not a general-purpose alternate login. It cannot broaden the signed scope, create new targets, issue credentials, disable audit, or perform operations that the credential excludes.

On reconnection, the agent synchronises the signed event chain, checks current revocation/policy state, and terminates or quarantines sessions that are no longer valid. A disconnected device cannot learn of a new revocation until it reconnects; therefore offline credentials must be short-lived and narrowly scoped, and high-risk operations must remain unavailable offline.

## 5. Mandatory pre-change gate

All router-changing operations must pass through one policy-enforced execution gateway:

`CONNECT → IDENTIFY → BASELINE → CHECKPOINT → VERIFY → RECOVERY GATE → AUTHORISE → EXECUTE → HEALTH CHECK → REPORT`

The gateway must be the only supported path for configuration writes, package changes, firmware staging, security tests that alter state, resets and restore operations.

Checkpoint requirements:

- bind the checkpoint to tenant ID, asset ID, exact hardware fingerprint and schema version;
- encrypt it using managed keys and authenticated encryption;
- record a cryptographic digest and verify the stored copy by reading it back;
- validate required configuration sections and reject partial or malformed exports;
- prevent concurrent sessions from replacing the active pre-change checkpoint;
- redact secrets in diagnostic exports without corrupting the encrypted restore copy;
- record who initiated, approved, created and verified the checkpoint.

A configuration checkpoint is not a full flash image. Backup success alone does not prove recoverability. The device must also have a tested recovery path recorded in the exact hardware revision's recovery profile.

If checkpoint or recovery validation fails, protected writes are blocked. The only exception is a narrowly defined, non-destructive recovery action whose policy explicitly states why a normal checkpoint is impossible and what independent recovery evidence is available.

## 6. Operation classes

| Class | Examples | Default policy |
|---|---|---|
| Read-only | inventory, status, logs, config analysis | Autonomous within device/tenant scope |
| Reversible configuration | approved WAN/LAN/Wi-Fi settings | Checkpoint + policy permission + health checks |
| Agent maintenance | restart or repair supported agent | Checkpoint where state may change; signed package |
| Firmware staging | verified image to supported inactive slot | Exact target, signature, recovery level and approval gates |
| Destructive recovery | flash erase, factory reset, partition rewrite | Explicit, step-up approval; physical presence where required |
| Active security test | validation against an in-scope target | Signed engagement, time window, rate limits, stop conditions |
| Third-party equipment | customer/vendor equipment | Explicit owner authorisation and exact scope |

No mode overrides tenant isolation, authorisation, audit, firmware signature checks, or the recovery gate. Credential guessing against live router logins, stealth, persistence, and out-of-scope exploitation are not autonomous agent capabilities.

## 7. Recovery levels

Use the existing per-hardware R0–R4 registry:

- **R0:** no verified recovery; firmware writes disabled.
- **R1:** tested configuration restore; firmware replacement disabled.
- **R2:** vendor recovery documented and lab-tested; physical/local operator required.
- **R3:** A/B trial boot and automatic rollback validated on the exact board.
- **R4:** R3 plus tested fleet failover and remote recovery orchestration.

Never infer recovery level from a marketing model name. An unverified hardware revision remains read-only / no-flash.

## 8. Session and audit lifecycle

Each session records: session ID, engineer ID, tenant ID, asset ID, activation mode, credential ID (never the credential secret), policy version, start/end time, allowed operations, checkpoint digest, recovery readiness, command/action summaries, approval references, result and synchronisation status.

Audit events are append-only and hash-chained or signed. Store locally in a protected queue while offline. On reconnect, upload idempotently and retain local evidence until the server confirms durable receipt. Clock rollback, chain gaps, signature failures or storage exhaustion must suspend privileged writes and raise an incident.

Provide immediate session termination, credential revocation, and a fleet emergency-access disable policy. The kill switch must not disable the minimum documented physical recovery path.

## 9. Failure behaviour

- Cloud outage: local NEARS may operate only within a valid cached signed credential and its original scope.
- Invalid/expired credential: deny activation; do not fall back to a shared password.
- Unknown device or hardware revision: read-only; no firmware write.
- Missing or unverifiable checkpoint: block protected changes.
- Recovery level R0: block firmware write and destructive configuration changes.
- Audit storage unavailable or tampered: suspend privileged writes.
- WAN loss during change: stop subsequent steps, preserve local evidence and follow only the prevalidated device-specific recovery plan.
- Failed health gate: invoke rollback only if that exact rollback method has been tested; otherwise stop and raise an incident.

## 10. Delivery milestones and acceptance tests

1. Define and review identity, role and permission claims.
2. Implement credential signing/verification and key rotation.
3. Implement local session state, expiry, anti-replay and audit queue.
4. Route all mutating commands through the mandatory checkpoint/recovery gateway.
5. Add cloud issuance, revocation and audit reconciliation.
6. Integrate Firmware Manager and CPE agent.
7. Test cloud outage, expired/revoked credentials, wrong tenant/device, replay, clock rollback, disk-full, tampered audit, backup corruption and concurrent sessions.
8. Validate recovery adapters on spare devices for each exact hardware revision before enabling writes.

Release acceptance requires tests proving that invalid credentials are rejected, tenant/device mismatches are denied, offline scope cannot expand, all mutations pass the checkpoint/recovery gate, and audit records reconcile without loss.

## 11. Current implementation boundary

This document and its credential schema define the contract only. They do not establish that GOD MODE, NEARS, signed credential issuance, local audit synchronisation or hardware recovery are already implemented. Do not enable customer-facing privileged operations or firmware flashing until code, automated tests, security review and exact-hardware recovery tests pass.


## 12. Explicit deactivation, logout and time accounting

Engineer command: `GOD MODE DEACTIVATE`

This command is the normal, explicit end-of-session action. It must terminate the current privileged engineering session and log the engineer out of the privileged console without necessarily logging them out of their ordinary NexISP account.

On deactivation, the agent must:

1. Stop accepting new privileged commands for that session immediately.
2. Finish or safely cancel in-flight work according to the operation's transaction/recovery policy; never abandon a firmware or configuration transaction halfway through.
3. Capture the final device state and run the applicable post-change health checks.
4. Verify that any required final checkpoint, rollback marker and local audit events are durably stored.
5. Record a signed session-close event and synchronise it with NexISP when online.
6. Revoke the session token, clear privileged in-memory credentials and close privileged channels.
7. Return the console to ordinary, least-privilege mode and display a concise session summary.

### Required time records

Record at minimum:

- session ID, engineer ID, tenant ID and device/asset IDs;
- activation mode (GOD MODE or NEARS emergency);
- session start and end timestamps in UTC, plus the displayed local timezone;
- elapsed session duration, calculated using a monotonic clock while the process is running;
- active-work duration and idle duration, where reliable activity signals are available;
- reconnect/offline intervals for NEARS sessions;
- commands/actions performed, outcomes, approvals and checkpoint references;
- deactivation reason: engineer requested, timeout, revocation, policy violation, crash/reboot or emergency kill switch.

Wall-clock timestamps are for audit correlation; elapsed duration must not be calculated solely by subtracting wall-clock values because system time can change. If the device reboots or its monotonic clock resets during an offline session, record separate measured segments and mark any unmeasurable interval as unknown rather than inventing a duration.

### Idle timeout and unexpected termination

- Apply a configurable inactivity timeout and a maximum session lifetime.
- Warn the engineer before an idle timeout where the interface is available.
- On timeout, invoke the same safe close procedure as explicit deactivation.
- On crash, power loss or forced termination, persist a recovery/unclean-shutdown marker and reconcile the session as interrupted on next startup; do not fabricate a normal logout event.
- A lost cloud connection must not by itself end a valid NEARS local session, but the local credential expiry and operation scope remain enforced.

### Example console interaction

```text
> GOD MODE DEACTIVATE

[OK] New privileged commands blocked
[OK] In-flight operations safely closed
[OK] Final health checks recorded
[OK] Audit events persisted
[OK] Privileged credentials cleared
[OK] Engineering session closed

Engineer: engineer-identifier
Session: session-identifier
Started: 2026-11-21T17:00:00Z
Ended:   2026-11-21T18:12:30Z
Duration: 01:12:30
Active work: 01:04:10
Idle: 00:08:20
Actions: 14 completed, 0 failed
Audit sync: pending (offline) / confirmed (online)
Status: LOGGED OUT OF GOD MODE
```

The example values are illustrative only. The implementation must use actual recorded timestamps and measured durations.

### Acceptance tests for logout and accounting

1. Explicit deactivation blocks further privileged commands.
2. Session duration matches monotonic elapsed time within test tolerance.
3. Time-zone display changes do not alter stored UTC timestamps or elapsed duration.
4. Clock rollback/forward changes do not create negative or inflated duration.
5. Idle timeout safely closes a session without interrupting an unsafe in-flight transaction.
6. Reboot/crash is recorded as interrupted, not as a successful user logout.
7. Offline NEARS close events synchronise idempotently after reconnection.
8. Session tokens and privileged in-memory secrets are invalidated on close.
9. The audit summary can be retrieved by authorised auditors without exposing credential secrets.

The command `GOD MODE DEACTIVATE` is mandatory in the engineering command interface and must be documented in the operator guide and training material.


## 13. Automatic idle logout and maximum session lifetime

GOD MODE must automatically deactivate when the authenticated engineer is inactive. Explicit `GOD MODE DEACTIVATE` remains available and is the preferred normal logout path.

### Initial default policy

These are proposed secure defaults and must be configurable by an authorised security administrator:

- At **10 minutes of inactivity**: display a clear warning and countdown.
- At **15 minutes of inactivity**: lock the privileged console, stop accepting new privileged commands, and begin the safe deactivation procedure.
- At **8 hours maximum session lifetime**: require a fresh authentication and a new privileged session, even if the engineer has remained active.

The idle warning may offer a brief authenticated “I am here” action to continue the session, subject to policy. Merely leaving a terminal open, background polling, device telemetry, automated agent activity or a running unattended job must not count as human activity or reset the engineer's idle timer.

### Definition of meaningful activity

Only deliberate, authenticated engineer interaction with the privileged console counts as activity, such as issuing a command, completing a step-up authentication or using an approved interactive control. The agent must not infer human presence from network traffic, keyboard focus, mouse movement alone, background jobs or another engineer's activity.

### Safe handling of work in progress

Idle logout must not abruptly interrupt a state-changing transaction. At the idle threshold:

1. Lock the privileged interface and reject new commands.
2. Allow an already-running operation only to reach its next defined safe checkpoint or transaction boundary.
3. Cancel work that can be safely cancelled; otherwise follow its prevalidated completion/rollback policy.
4. Do not leave a firmware/configuration transaction in an indeterminate state.
5. Close the human GOD MODE session, clear its privileged credentials and record the timeout reason.

Any unattended task that is permitted to continue after the engineer session closes must be separately authorised, bounded, auditable and run under a restricted service/job identity—not under the engineer's still-open GOD MODE session. It must not gain additional permissions from the engineer's session.

### Session accounting

Record separate timestamps and durations for:

- human interactive session;
- idle period before automatic logout;
- any separately authorised background job;
- safe-close/transaction-drain period.

Use a monotonic clock for elapsed durations and UTC wall-clock timestamps for audit correlation. If a reboot or clock discontinuity makes a duration unknowable, mark it unknown rather than estimating it.

### Acceptance tests

1. The warning appears at the configured idle-warning threshold.
2. No meaningful human activity by the idle deadline causes automatic privileged logout.
3. Meaningful authenticated interaction resets the idle timer only when policy permits.
4. Background polling, telemetry and automated jobs do not reset the timer.
5. The maximum session lifetime forces re-authentication.
6. The console locks immediately at timeout and rejects new privileged commands.
7. In-flight changes reach a safe transaction boundary or follow their tested cancellation/rollback path.
8. An authorised background job is separately identified and audited after human logout.
9. Timeout closure is recorded distinctly from manual logout, revocation, crash or emergency termination.
10. The timeout event and measured duration synchronise idempotently after an offline NEARS session reconnects.

The timeout values above are defaults for initial implementation and must be reviewed through security testing and operational policy before production deployment.
