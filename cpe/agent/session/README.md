# GOD MODE session lifecycle package

This package provides the first local, in-memory state machine for privileged-session timeout and closure. It is not yet wired into the agent's command parser, HTTP/Unix-socket API, persistent audit queue, NexISP identity service or router mutation gateway.

## Defaults

- Warning at 10 minutes of human inactivity.
- Lock and begin safe logout at 15 minutes of human inactivity.
- Force re-authentication after 8 hours maximum session lifetime.

The policy is configurable by the caller and validated before session creation.

## Integration contract

1. Create a session only after the identity/MFA, role, tenant, asset and signed policy checks succeed.
2. Call `RecordHumanActivity` only after an authenticated, deliberate engineer interaction. Never call it for telemetry, polling, background jobs, keyboard focus or another engineer's activity.
3. A watchdog owned by the agent calls `Evaluate` periodically using `time.Now()`. Use the returned warning to notify the engineer.
4. When `LockNow` is true, immediately lock the privileged console and reject new commands.
5. If an operation is in flight, mark it using `SetOperationInFlight(true)`. The operation owner must finish or safely cancel at a tested transaction boundary, then call `SetOperationInFlight(false)` and `CompleteSafeBoundary`.
6. Persist a signed close event, invalidate session credentials and synchronise the audit record before reporting a completed logout. Those persistence and credential-revocation steps are integration work, not implemented by this package.
7. Run unattended tasks only under separately scoped service identities; they must not inherit the engineer session.

## Clock and accounting

Use Go `time.Now()` values directly while the process is alive. Their monotonic component supports elapsed-time comparisons despite wall-clock changes. Store UTC timestamps for audit correlation, but never reconstruct elapsed duration by subtracting persisted wall-clock timestamps. After reboot or loss of monotonic continuity, mark any unmeasurable interval unknown and close/reconcile the previous session as interrupted.

The package reports elapsed and idle duration. It does not infer precise active-work duration from keyboard or mouse events; that requires a separately designed, privacy-conscious foreground-work measurement policy.

## Safety boundary

This package does not execute router changes, perform backups, cancel firmware operations, or perform rollback. Callers must integrate it with the mandatory checkpoint/recovery transaction gateway. A timeout locks the human session immediately; an in-flight operation must reach a safe boundary before the session is finally closed.
